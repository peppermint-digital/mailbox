<?php

namespace Peppermint\Mailbox\Jmap;

use Closure;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Contracts\TokenRefresher;
use Peppermint\Mailbox\Folders\FolderNames;
use Peppermint\Mailbox\Imap\FolderPaths;
use Peppermint\Mailbox\Imap\FolderResolver;
use Peppermint\Mailbox\Imap\MessageFormatter;
use Peppermint\Mailbox\Imap\MessagePage;
use Peppermint\Mailbox\Imap\SystemFolders;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Search\Criteria;
use Peppermint\Mailbox\Threading\ThreadPage;
use RuntimeException;

/**
 * A mailbox reached over JMAP (RFC 8620/8621).
 *
 * The second transport, not the new one: Office 365 and Google speak IMAP and
 * nothing else, so most mailboxes stay where they are. This exists because our
 * own Stalwart speaks JMAP, and the difference is not cosmetic — listing a
 * folder is one HTTP request that carries the query AND the rows, where IMAP
 * needs a connection, a select, a search and a fetch.
 *
 * ## What this class deliberately does not do
 *
 * **It does not thread, sort or paginate.** The server could, and one day it
 * should. Today {@see MessagePage} does it for every transport, and a JMAP
 * mailbox that came back in a different order than an IMAP one would be a
 * difference nobody asked for in a list nobody changed.
 *
 * **It does not cache the session document between requests.** One fetch per
 * client is cheap; a cached document is a thing that goes stale exactly when a
 * server changes, which is the moment it matters.
 *
 * ## Reading marks nothing
 *
 * Unlike IMAP, where fetching a body sets `\Seen` unless every call remembers
 * to PEEK, JMAP changes a keyword only when asked to. The whole class of bug
 * where opening a mail in one product marks it read in another does not exist
 * here.
 */
class JmapClient implements Mailbox
{
    private ?JmapSession $session = null;

    /** Folder rows, for the duration of one batch. */
    private ?array $folders = null;

    private bool $batching = false;

    public function __construct(
        private readonly MailAccount $account,
        private readonly ?TokenRefresher $refresher = null,
        /**
         * Sends one HTTP request and returns the decoded body.
         *
         * Injectable for the same reason the IMAP client takes a connector:
         * the whole class has to be showable without a server.
         *
         * @var null|Closure(string, string, array, array): array
         */
        private readonly ?Closure $sender = null,
        private readonly JmapMessageFormatter $formatter = new JmapMessageFormatter,
        private readonly int $timeout = 30,
    ) {
        if (! $sender && ! class_exists(Http::class)) {
            throw new RuntimeException('JMAP needs an HTTP client; none is available.');
        }
    }

    /**
     * Runs several operations against one session.
     *
     * There is no connection to hold open — the saving here is the session
     * document and the folder list, which would otherwise be fetched again for
     * every single call.
     *
     * @template T
     *
     * @param  callable(self): T  $work
     * @return T
     */
    public function batch(callable $work): mixed
    {
        if ($this->batching) {
            return $work($this);
        }

        $this->batching = true;

        try {
            return $work($this);
        } finally {
            $this->batching = false;
            $this->folders = null;
        }
    }

    /**
     * The folders of this mailbox, as plain rows.
     *
     * The server's `role` becomes an IMAP special-use flag on the way out —
     * `\Sent`, `\Trash`, and so on. That is not decoration: SystemFolders and
     * FolderNames already read those flags, and a JMAP mailbox that reported
     * its roles in JMAP's own words would need a second copy of both.
     *
     * The flag is also the better answer. Over IMAP the trash folder is found
     * by its NAME, in whatever language the server chose; here the server says
     * what it is.
     *
     * @return list<array{name: string, path: string, flags: list<string>, id: string, role: string|null}>
     */
    public function folders(): array
    {
        if ($this->folders !== null) {
            return $this->folders;
        }

        $antwort = $this->call([['Mailbox/get', [
            'accountId' => $this->session()->accountId,
            'properties' => ['id', 'name', 'parentId', 'role', 'sortOrder'],
        ], 'f0']]);

        $roh = $antwort[0][1]['list'] ?? [];
        $nachId = [];

        foreach ($roh as $ordner) {
            $nachId[$ordner['id']] = $ordner;
        }

        $zeilen = [];

        foreach ($roh as $ordner) {
            $zeilen[] = [
                'id' => $ordner['id'],
                'name' => $ordner['name'],
                'path' => $this->path($ordner, $nachId),
                'role' => $ordner['role'] ?? null,
                'flags' => $this->flags($ordner['role'] ?? null),
            ];
        }

        if ($this->batching) {
            $this->folders = $zeilen;
        }

        return $zeilen;
    }

    /**
     * Not offered over JMAP, and not silently skipped either.
     *
     * A JMAP server assigns roles itself and creates what it needs; the IMAP
     * dance of guessing whether the drafts folder is called `Drafts`,
     * `INBOX.Drafts` or `Entwürfe` has no equivalent here. Answering with an
     * empty list would read as "nothing was missing".
     */
    public function ensureStandardFolders(): array
    {
        throw new RuntimeException(
            'A JMAP server maintains its own standard folders; there is nothing to create.'
        );
    }

    /**
     * A new folder, nested where the path says.
     *
     * JMAP has no paths: everything but the last segment names the parent, and
     * a parent that does not exist is refused rather than invented. Creating
     * the chain silently would turn a typo into a folder tree.
     */
    public function createFolder(string $path): void
    {
        [$eltern, $name] = $this->splitPath($path);

        $this->set('Mailbox/set', [
            'create' => ['neu' => array_filter([
                'name' => $name,
                'parentId' => $eltern,
            ], fn ($wert): bool => $wert !== null)],
        ], 'create');

        $this->folders = null;
    }

    /**
     * Renames a folder, keeping it where it is.
     */
    public function renameFolder(string $path, string $newName): string
    {
        $ordner = $this->folderOrFail($path);
        $this->refuseSystemFolder($ordner);

        $this->set('Mailbox/set', [
            'update' => [$ordner['id'] => ['name' => $newName]],
        ], 'update');

        $this->folders = null;

        return FolderPaths::renamed($ordner['path'], $newName, '/');
    }

    /**
     * Deletes a folder.
     *
     * A folder that still holds mail is refused BY THE SERVER, and that
     * refusal is passed on. JMAP offers to delete the contents along with it;
     * not asking for that is the point. Over IMAP the same call takes the mail
     * with it, which is the more dangerous of the two behaviours — here the
     * person finds out first.
     */
    public function deleteFolder(string $path): void
    {
        $ordner = $this->folderOrFail($path);
        $this->refuseSystemFolder($ordner);

        $this->set('Mailbox/set', ['destroy' => [$ordner['id']]], 'destroy');

        $this->folders = null;
    }

    /**
     * Header rows of a folder, plus how many there are in total.
     *
     * Query and rows in ONE request: `Email/get` reads its ids from the
     * `Email/query` right before it through a back-reference, so the server
     * resolves both without us learning the ids first. That is the whole
     * difference to IMAP in one line.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function headerRows(string $folder, int $limit = MessagePage::FETCH_LIMIT): array
    {
        $ordner = $this->folderNamed($folder);

        if ($ordner === null) {
            return [[], 0];
        }

        $antwort = $this->call([
            ['Email/query', [
                'accountId' => $this->session()->accountId,
                'filter' => ['inMailbox' => $ordner['id']],
                'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
                'limit' => $limit,
                'calculateTotal' => true,
            ], 'q0'],
            ['Email/get', [
                'accountId' => $this->session()->accountId,
                '#ids' => ['resultOf' => 'q0', 'name' => 'Email/query', 'path' => '/ids'],
                'properties' => self::SUMMARY_PROPERTIES,
                'bodyProperties' => self::BODY_PROPERTIES,
            ], 'g0'],
        ]);

        $gesamt = (int) ($this->antwortZu($antwort, 'q0')['total'] ?? 0);
        $mails = $this->antwortZu($antwort, 'g0')['list'] ?? [];

        return [array_map(fn (array $mail): array => $this->formatter->summary($mail), $mails), $gesamt];
    }

    /**
     * One page of a folder's message list.
     *
     * Dieselben Regeln wie ueber IMAP — nur ohne die teure Stelle: JMAP
     * liefert die Vorschau mit der Zeile, es gibt keinen Rumpf nachzuholen.
     * Die Entdopplung im Gesendet-Ordner bleibt trotzdem: Sie haengt am
     * Server, der beim Senden selbst eine Kopie ablegt, nicht am Protokoll.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function page(string $folder, int $page = 1, int $perPage = 25): array
    {
        [$zeilen, $gesamt] = $this->headerRows($folder, MessagePage::FETCH_LIMIT);

        $zeilen = MessagePage::sortByDateDesc($zeilen);

        if (MessagePage::isSentFolder($folder)) {
            $zeilen = MessagePage::dedupe($zeilen);
        }

        return [
            MessagePage::slice($zeilen, $page, $perPage),
            MessagePage::effectiveTotal($gesamt, count($zeilen)),
        ];
    }

    /**
     * One page of conversations.
     *
     * The server knows its own threads (`threadId`, `collapseThreads`) and
     * could do this in one call. It deliberately does not: the grouping rules
     * are the same for every transport, and a JMAP mailbox whose conversations
     * were cut differently than an IMAP one would be a difference nobody
     * ordered — in a list nobody changed. Server-side threading is worth
     * having, as a decision, not as a side effect of the transport.
     *
     * What IS different: nothing has to be held back here. JMAP delivers the
     * preview with the row, so there is no body to fetch later and no reason
     * to format only the visible page.
     *
     * @param  null|callable(list<string>): list<array<string, mixed>>  $ownReplies
     * @param  list<string>  $excludeThreadIds
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function threads(
        string $folder,
        int $page = 1,
        int $perPage = 25,
        ?callable $ownReplies = null,
        array $excludeThreadIds = [],
    ): array {
        [$zeilen] = $this->headerRows($folder, MessagePage::FETCH_LIMIT);

        return ThreadPage::of(
            $zeilen,
            $ownReplies,
            $page,
            $perPage,
            $excludeThreadIds,
            fn (array $zeile): array => $zeile,
        );
    }

    /**
     * Search one folder.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function search(string $folder, Criteria $criteria, int $limit = 50): array
    {
        $this->refuseEmpty($criteria);

        $ordner = $this->folderNamed($folder);

        if ($ordner === null) {
            return [[], 0];
        }

        [$mails, $gesamt] = $this->find(array_merge($criteria->toJmapFilter(), ['inMailbox' => $ordner['id']]), $limit);

        return [array_map(fn (array $m): array => $this->formatter->summary($m), $mails), $gesamt];
    }

    /**
     * Search every folder worth searching — in ONE request.
     *
     * JMAP has `inMailboxOtherThan`, so the folders to leave out are named and
     * the rest is searched together. The IMAP side has to walk folder by
     * folder and cap each one, because IMAP cannot query across folders at all.
     *
     * That makes `$perFolder` meaningless here, and the difference is worth
     * stating rather than hiding: over IMAP a busy folder can crowd the result
     * out at ten hits, while this returns the genuinely newest ones across the
     * mailbox. Same shape, better answer — and the parameter stays in the
     * signature so a product does not have to ask which transport it is on.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    public function searchAll(Criteria $criteria, int $limit = 50, int $perFolder = 10): array
    {
        $this->refuseEmpty($criteria);

        $ordner = $this->folders();
        $raus = array_values(array_filter(
            $ordner,
            fn (array $f): bool => in_array($f['role'], ['trash', 'junk', 'drafts'], true)
                || FolderNames::isExcludedFromSearch($f['path']),
        ));

        $filter = $criteria->toJmapFilter();

        if ($raus !== []) {
            $filter['inMailboxOtherThan'] = array_column($raus, 'id');
        }

        [$mails, $gesamt] = $this->find($filter, $limit, ['mailboxIds']);

        $pfadZu = array_column($ordner, 'path', 'id');
        $zeilen = [];

        foreach ($mails as $mail) {
            $zeile = $this->formatter->summary($mail);
            // Ein Treffer ohne seinen Ordner laesst sich nicht oeffnen. Eine
            // Mail kann in mehreren liegen; genommen wird der erste, der nicht
            // ausgeschlossen ist — der, in dem sie gefunden wurde.
            $zeile['folder'] = $this->foundIn($mail, $pfadZu, array_column($raus, 'id'));
            $zeilen[] = $zeile;
        }

        return [$zeilen, $gesamt, count($ordner) - count($raus)];
    }

    /**
     * @param  array<string, string>  $pfadZu  Ordner-ID → Pfad
     * @param  list<string>  $ausgeschlossen
     */
    private function foundIn(array $mail, array $pfadZu, array $ausgeschlossen): ?string
    {
        foreach (array_keys($mail['mailboxIds'] ?? []) as $id) {
            if (! in_array($id, $ausgeschlossen, true) && isset($pfadZu[$id])) {
                return $pfadZu[$id];
            }
        }

        return null;
    }

    /**
     * Query and rows in one request — the shape every listing here uses.
     *
     * @param  array<string, mixed>  $filter
     * @param  list<string>  $extra  additional properties this caller needs
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function find(array $filter, int $limit, array $extra = []): array
    {
        $antwort = $this->call([
            ['Email/query', [
                'accountId' => $this->session()->accountId,
                'filter' => $filter,
                'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
                'limit' => $limit,
                'calculateTotal' => true,
            ], 'q0'],
            ['Email/get', [
                'accountId' => $this->session()->accountId,
                '#ids' => ['resultOf' => 'q0', 'name' => 'Email/query', 'path' => '/ids'],
                'properties' => array_merge(self::SUMMARY_PROPERTIES, $extra),
                'bodyProperties' => self::BODY_PROPERTIES,
            ], 'g0'],
        ]);

        return [
            $this->antwortZu($antwort, 'g0')['list'] ?? [],
            (int) ($this->antwortZu($antwort, 'q0')['total'] ?? 0),
        ];
    }

    private function refuseEmpty(Criteria $criteria): void
    {
        if ($criteria->isEmpty()) {
            throw new InvalidArgumentException(
                'An empty search would match every message in the mailbox. Ask for something.'
            );
        }
    }

    /**
     * The path of a standard folder.
     *
     * Here the role decides outright — der Server sagt selbst, welcher Ordner
     * welcher ist. Der Namensvergleich bleibt fuer Ordner ohne Rolle.
     */
    public function specialFolder(string $kind): ?string
    {
        $rolle = mb_strtolower($kind) === 'archive' ? 'archive' : mb_strtolower($kind);
        $ueberName = null;

        foreach ($this->folders() as $ordner) {
            if ($ordner['role'] === $rolle) {
                return $ordner['path'];
            }

            if ($ueberName === null && FolderNames::looksLike($ordner['path'], $kind)) {
                $ueberName = $ordner['path'];
            }
        }

        return $ueberName;
    }

    /**
     * How many messages are newer than a point in time.
     *
     * Der Server zaehlt selbst — `$scan` ist hier eine Obergrenze und keine
     * Notwendigkeit. Genau deshalb steht sie trotzdem in der Antwort: Ein
     * Produkt, das ueber IMAP hoechstens `$scan` bekommt, darf ueber JMAP
     * nicht ploetzlich eine andere Zahl sehen.
     */
    public function countNewSince(string $folder, \DateTimeInterface $since, int $scan = 50): int
    {
        $ordner = $this->folderNamed($folder);

        if ($ordner === null) {
            return 0;
        }

        $antwort = $this->call([['Email/query', [
            'accountId' => $this->session()->accountId,
            'filter' => [
                'inMailbox' => $ordner['id'],
                'after' => (new \DateTimeImmutable('@'.$since->getTimestamp()))->format('Y-m-d\TH:i:s\Z'),
            ],
            'limit' => 0,
            'calculateTotal' => true,
        ], 'c0']]);

        return min((int) ($this->antwortZu($antwort, 'c0')['total'] ?? 0), $scan);
    }

    /**
     * Moves several messages into the archive folder — in ONE request.
     *
     * @param  list<int|string>  $uids
     * @return array{archived: int, failed: int}
     */
    public function archive(string $folder, array $uids): array
    {
        $uids = array_values(array_unique($uids));

        if ($uids === []) {
            return ['archived' => 0, 'failed' => 0];
        }

        $ziel = $this->specialFolder('Archive');

        if ($ziel === null) {
            return ['archived' => 0, 'failed' => count($uids)];
        }

        if ($ziel === $folder) {
            return ['archived' => count($uids), 'failed' => 0];
        }

        $zielOrdner = $this->folderNamed($ziel);
        $aenderungen = [];

        foreach ($uids as $uid) {
            $aenderungen[(string) $uid] = ['mailboxIds' => [$zielOrdner['id'] => true]];
        }

        $ergebnis = $this->antwortZu($this->call([['Email/set', [
            'accountId' => $this->session()->accountId,
            'update' => $aenderungen,
        ], 's0']]), 's0');

        $geschafft = count($ergebnis['updated'] ?? []);

        return ['archived' => $geschafft, 'failed' => count($uids) - $geschafft];
    }

    /**
     * Finds messages whose Message-ID carries this token.
     *
     * @return list<int|string>
     */
    public function findByToken(string $folder, string $token, int $limit = 20): array
    {
        $ordner = $this->folderNamed($folder);

        if ($ordner === null) {
            return [];
        }

        [$mails] = $this->find(['inMailbox' => $ordner['id'], 'text' => $token], $limit);

        $treffer = [];

        foreach ($mails as $mail) {
            // Wie ueber IMAP: Die Volltextsuche trifft auch ein Zitat.
            // Entschieden wird an der Message-ID.
            foreach ((array) ($mail['messageId'] ?? []) as $id) {
                if (str_contains((string) $id, $token)) {
                    $treffer[] = $mail['id'];

                    break;
                }
            }
        }

        return $treffer;
    }

    /**
     * Can this mailbox be reached?
     *
     * @return array{ok: bool, message: string}
     */
    public function probe(): array
    {
        try {
            $ordner = $this->folders();

            return ['ok' => true, 'message' => 'Verbindung steht, '.count($ordner).' Ordner gefunden.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * One message, in full. Null when it is not there any more.
     */
    public function message(string $folder, int|string $uid): ?array
    {
        $mail = $this->email((string) $uid, self::FULL_PROPERTIES);

        if ($mail === null) {
            return null;
        }

        return $this->formatter->full($mail, fn (string $blobId, string $name, string $type): ?string => $this->blob($blobId, $name, $type));
    }

    /**
     * One attachment, with its bytes.
     */
    public function attachment(string $folder, int|string $uid, int $index): ?array
    {
        $mail = $this->email((string) $uid, ['id', 'attachments']);

        if ($mail === null) {
            return null;
        }

        $teil = $this->formatter->partAt($mail, $index);

        if ($teil === null) {
            return null;
        }

        $name = (string) ($teil['name'] ?? 'attachment');
        $type = (string) ($teil['type'] ?? 'application/octet-stream');
        $bytes = $this->blob((string) ($teil['blobId'] ?? ''), $name, $type);

        if ($bytes === null) {
            return null;
        }

        return ['filename' => $name, 'mime_type' => $type, 'contents' => $bytes];
    }

    /**
     * Marks a message read or unread.
     *
     * A keyword is set with `true` and removed with `null` — JSON has no third
     * value here, and `false` would be stored as a keyword that is present and
     * false, which no other client understands.
     */
    /**
     * Every attachment of a message, with its bytes.
     *
     * Je Datei ein Blob-Abruf — anders als ueber IMAP, wo mit der Nachricht
     * ohnehin alles auf einmal kommt. Dafuer holt der Weg hierher nur, was
     * wirklich gebraucht wird: eine Mail mit zwoelf Bildern und einem PDF
     * kostet hier einen Abruf, dort den ganzen Rumpf.
     *
     * @return list<array{filename: string, mime_type: string|null, contents: string}>
     */
    public function attachments(string $folder, int|string $uid): array
    {
        $mail = $this->email((string) $uid, ['id', 'attachments']);

        if ($mail === null) {
            return [];
        }

        $anhaenge = [];

        foreach ($mail['attachments'] ?? [] as $teil) {
            $typ = (string) ($teil['type'] ?? '');

            if (MessageFormatter::isEmbeddedImage($teil['cid'] ?? null, $teil['disposition'] ?? null, $typ)) {
                continue;
            }

            $name = (string) ($teil['name'] ?? 'attachment');
            $bytes = $this->blob((string) ($teil['blobId'] ?? ''), $name, $typ ?: 'application/octet-stream');

            if ($bytes === null) {
                continue;
            }

            $anhaenge[] = [
                'filename' => $name,
                'mime_type' => $teil['type'] ?? null,
                'contents' => $bytes,
            ];
        }

        return $anhaenge;
    }

    public function setSeen(string $folder, int|string $uid, bool $seen): bool
    {
        return $this->patch((string) $uid, ['keywords/$seen' => $seen ?: null]);
    }

    public function setFlagged(string $folder, int|string $uid, bool $flagged): bool
    {
        return $this->patch((string) $uid, ['keywords/$flagged' => $flagged ?: null]);
    }

    /**
     * Moves a message into another folder.
     *
     * `mailboxIds` is replaced rather than patched: a mail can live in several
     * folders at once in JMAP, and a patch would ADD the target instead of
     * moving. The result would be a mail that is still in the inbox after
     * being filed — visible only to whoever files.
     */
    public function move(string $from, int|string $uid, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $ziel = $this->folderNamed($to);

        if ($ziel === null) {
            return false;
        }

        return $this->patch((string) $uid, ['mailboxIds' => [$ziel['id'] => true]]);
    }

    /**
     * Deletes a message — into the trash where there is one.
     *
     * The trash is found by its ROLE, not by its name: the server says which
     * folder it is, in every language. The names are still accepted, because
     * the contract carries them and a server without roles has to land
     * somewhere.
     *
     * @param  list<string>  $trashNames
     */
    public function delete(string $folder, int|string $uid, array $trashNames = ['trash', 'papierkorb', 'deleted items', 'gelöschte elemente']): bool
    {
        $papierkorb = null;

        foreach ($this->folders() as $kandidat) {
            if ($kandidat['role'] === 'trash' || FolderPaths::matchesAny($kandidat['name'], $kandidat['path'], $trashNames)) {
                $papierkorb = $kandidat;
                break;
            }
        }

        $quelle = $this->folderNamed($folder);

        if ($papierkorb === null || ($quelle !== null && $quelle['id'] === $papierkorb['id'])) {
            // Already in the trash, or there is none: now it really goes.
            return $this->destroy((string) $uid);
        }

        return $this->patch((string) $uid, ['mailboxIds' => [$papierkorb['id'] => true]]);
    }

    /** What a list row needs, and not one property more. */
    private const SUMMARY_PROPERTIES = [
        'id', 'blobId', 'threadId', 'keywords', 'size', 'receivedAt', 'sentAt',
        'messageId', 'inReplyTo', 'references', 'subject', 'from',
        'hasAttachment', 'preview', 'attachments',
    ];

    /** What reading one message needs on top of that. */
    private const FULL_PROPERTIES = [
        'id', 'blobId', 'threadId', 'keywords', 'receivedAt', 'sentAt',
        'messageId', 'inReplyTo', 'references', 'subject', 'from', 'to', 'cc',
        'hasAttachment', 'attachments', 'textBody', 'htmlBody', 'bodyValues',
    ];

    private const BODY_PROPERTIES = [
        'partId', 'blobId', 'size', 'name', 'type', 'charset', 'disposition', 'cid',
    ];

    /**
     * One message by id, or null when the server does not know it.
     *
     * The folder is not part of the lookup: a JMAP id addresses the message
     * itself, not a place in a folder. Passing one in would suggest that
     * moving a mail changes its id, and then a product would refetch after
     * every move for no reason.
     *
     * @param  list<string>  $properties
     * @return array<string, mixed>|null
     */
    private function email(string $id, array $properties): ?array
    {
        $antwort = $this->call([['Email/get', [
            'accountId' => $this->session()->accountId,
            'ids' => [$id],
            'properties' => $properties,
            'bodyProperties' => self::BODY_PROPERTIES,
            'fetchAllBodyValues' => true,
        ], 'e0']]);

        return $this->antwortZu($antwort, 'e0')['list'][0] ?? null;
    }

    /**
     * The bytes of one blob, or null when it is gone.
     *
     * Every inline image is one of these. IMAP pays the same price in one go
     * by loading the whole message; here it is visible, which is the honest
     * version of the same cost.
     */
    private function blob(string $blobId, string $name, string $type): ?string
    {
        if ($blobId === '') {
            return null;
        }

        $antwort = ($this->sender ?? $this->defaultSender())(
            'GET',
            $this->session()->blobUrl($blobId, $name, $type),
            [],
            $this->headers(),
        );

        return $antwort['__raw'] ?? null;
    }

    /**
     * The folder meant by a name or a path.
     *
     * ## INBOX is a name, not a spelling
     *
     * IMAP guarantees every mailbox an inbox called INBOX, and it is the one
     * folder name the protocol treats case-insensitively. Every product
     * therefore asks for `INBOX` — it is the default in both mail browsers.
     *
     * JMAP has no such name. Our server calls the folder `Inbox`, and an exact
     * match against `INBOX` finds nothing. That does not fail: the list comes
     * back empty, the mailbox looks empty, and nobody sees an error. Measured
     * live on 17.09.2026, and the reason this comparison exists.
     *
     * So the role decides: the server says which folder is the inbox, in every
     * spelling and every language. Exact matches still win — a folder someone
     * literally named "INBOX" is theirs, not ours to reinterpret.
     *
     * @return array<string, mixed>|null
     */
    private function folderNamed(string $wanted): ?array
    {
        $genau = FolderResolver::resolve(
            $this->folders(),
            $wanted,
            fn (array $f): string => $f['path'],
            fn (array $f): string => $f['name'],
        );

        if ($genau !== null) {
            return $genau;
        }

        if (mb_strtoupper($wanted) === 'INBOX') {
            foreach ($this->folders() as $ordner) {
                if ($ordner['role'] === 'inbox') {
                    return $ordner;
                }
            }
        }

        // Last resort, and only on case: a product that stored "archives/2025"
        // means the same folder as "Archives/2025". Anything beyond case stays
        // a miss — guessing further is how the wrong folder gets opened.
        $gesucht = mb_strtolower($wanted);

        foreach ($this->folders() as $ordner) {
            if (mb_strtolower($ordner['path']) === $gesucht || mb_strtolower($ordner['name']) === $gesucht) {
                return $ordner;
            }
        }

        return null;
    }

    /**
     * The path of a folder, built from its parents.
     *
     * JMAP has no paths, only parents — but everything above this class
     * addresses a folder by string, and a product that stored "Archive/2026"
     * must keep finding it.
     *
     * @param  array<string, mixed>  $folder
     * @param  array<string, array<string, mixed>>  $byId
     */
    private function path(array $folder, array $byId): string
    {
        $teile = [$folder['name']];
        $eltern = $folder['parentId'] ?? null;
        $tiefe = 0;

        // A parent chain that points at itself would spin here. Servers do not
        // do that on purpose, but a loop in a mailbox tree is not worth a hung
        // request.
        while ($eltern !== null && isset($byId[$eltern]) && $tiefe++ < 20) {
            array_unshift($teile, $byId[$eltern]['name']);
            $eltern = $byId[$eltern]['parentId'] ?? null;
        }

        return implode('/', $teile);
    }

    /**
     * The special-use flag that matches a JMAP role.
     *
     * @return list<string>
     */
    private function flags(?string $role): array
    {
        return match ($role) {
            'inbox' => ['\\Inbox'],
            'sent' => ['\\Sent'],
            'drafts' => ['\\Drafts'],
            'trash' => ['\\Trash'],
            'junk' => ['\\Junk'],
            'archive' => ['\\Archive'],
            'all' => ['\\All'],
            'flagged' => ['\\Flagged'],
            'important' => ['\\Important'],
            default => [],
        };
    }

    /**
     * One JMAP request with one or more method calls.
     *
     * @param  list<array{0: string, 1: array<string, mixed>, 2: string}>  $methodCalls
     * @return list<array{0: string, 1: array<string, mixed>, 2: string}>
     */
    private function call(array $methodCalls): array
    {
        $antwort = ($this->sender ?? $this->defaultSender())(
            'POST',
            $this->session()->apiUrl,
            [
                'using' => [JmapSession::CORE, JmapSession::MAIL],
                'methodCalls' => $methodCalls,
            ],
            $this->headers(),
        );

        $antworten = $antwort['methodResponses'] ?? [];

        foreach ($antworten as $einzeln) {
            if (($einzeln[0] ?? null) === 'error') {
                // A JMAP error travels with HTTP 200, so nothing below would
                // notice it — a list would simply come back empty.
                $typ = $einzeln[1]['type'] ?? 'unknown';

                throw new RuntimeException("The JMAP server refused the call: {$typ}");
            }
        }

        return $antworten;
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>, 2: string}>  $responses
     * @return array<string, mixed>
     */
    private function antwortZu(array $responses, string $callId): array
    {
        foreach ($responses as $antwort) {
            if (($antwort[2] ?? null) === $callId) {
                return $antwort[1] ?? [];
            }
        }

        return [];
    }

    private function session(): JmapSession
    {
        if ($this->session !== null) {
            return $this->session;
        }

        if ($this->refresher && $this->account->usesOAuth() && $this->account->isTokenExpiringSoon()) {
            $this->refresher->ensureFresh($this->account);
        }

        $dokument = ($this->sender ?? $this->defaultSender())(
            'GET',
            JmapSession::urlFor($this->account),
            [],
            $this->headers(),
        );

        return $this->session = JmapSession::fromDocument($dokument);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        if ($this->account->usesOAuth()) {
            $token = $this->account->field('oauth_access_token');

            if (! is_string($token) || $token === '') {
                // The same rule as on the IMAP side: a mailbox without a token
                // stops here rather than connecting as nobody.
                throw new RuntimeException('This OAuth mailbox has no access token.');
            }

            return ['Authorization' => 'Bearer '.$token];
        }

        $benutzer = $this->account->field('username') ?: $this->account->field('email');
        $kennwort = (string) $this->account->field('password');

        return ['Authorization' => 'Basic '.base64_encode($benutzer.':'.$kennwort)];
    }

    /**
     * The real HTTP call.
     *
     * A GET that is not JSON — a blob — comes back under `__raw`, so that the
     * one sender can serve both without the callers having to know which.
     */
    private function defaultSender(): Closure
    {
        return function (string $method, string $url, array $payload, array $headers): array {
            $antwort = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->when($method === 'POST', fn ($http) => $http->asJson());

            $ergebnis = $method === 'POST'
                ? $antwort->post($url, $payload)
                : $antwort->get($url);

            if ($ergebnis->failed()) {
                throw new RuntimeException(
                    "JMAP request to {$url} failed with HTTP {$ergebnis->status()}."
                );
            }

            $json = $ergebnis->json();

            return is_array($json) ? $json : ['__raw' => $ergebnis->body()];
        };
    }

    /**
     * Changes one message, and says whether it was there to change.
     *
     * The distinction matters more than it looks: a shared mailbox moves under
     * the person reading it, and "the mail a colleague just filed" must come
     * back as `false` — not as an exception about an id nobody typed. Anything
     * else the server refuses IS an exception, because it means the change did
     * not happen for a reason the person can do something about.
     *
     * @param  array<string, mixed>  $patch
     */
    private function patch(string $id, array $patch): bool
    {
        $ergebnis = $this->antwortZu($this->call([['Email/set', [
            'accountId' => $this->session()->accountId,
            'update' => [$id => $patch],
        ], 's0']]), 's0');

        if (array_key_exists($id, $ergebnis['updated'] ?? [])) {
            return true;
        }

        return $this->refused($ergebnis['notUpdated'][$id] ?? null, 'change');
    }

    private function destroy(string $id): bool
    {
        $ergebnis = $this->antwortZu($this->call([['Email/set', [
            'accountId' => $this->session()->accountId,
            'destroy' => [$id],
        ], 's0']]), 's0');

        if (in_array($id, $ergebnis['destroyed'] ?? [], true)) {
            return true;
        }

        return $this->refused($ergebnis['notDestroyed'][$id] ?? null, 'delete');
    }

    /**
     * A refusal for one message: gone, or a real problem?
     *
     * @param  array<string, mixed>|null  $fehler
     */
    private function refused(?array $fehler, string $was): bool
    {
        $typ = $fehler['type'] ?? null;

        if ($typ === null || $typ === 'notFound') {
            return false;
        }

        throw new RuntimeException("The server refused to {$was} this message: {$typ}");
    }

    /**
     * A folder operation — which either works or says why.
     *
     * Unlike a message, a folder does not quietly disappear under someone, so
     * there is no "it was already gone" case worth swallowing here.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function set(string $method, array $arguments, string $kind): void
    {
        $ergebnis = $this->antwortZu($this->call([[$method, array_merge(
            ['accountId' => $this->session()->accountId],
            $arguments,
        ), 's0']]), 's0');

        $abgelehnt = match ($kind) {
            'create' => $ergebnis['notCreated'] ?? [],
            'update' => $ergebnis['notUpdated'] ?? [],
            default => $ergebnis['notDestroyed'] ?? [],
        };

        if ($abgelehnt === []) {
            return;
        }

        $erster = reset($abgelehnt);
        $typ = $erster['type'] ?? 'unknown';
        $grund = $erster['description'] ?? null;

        throw new RuntimeException(
            "The server refused this folder operation: {$typ}".($grund ? " ({$grund})" : '')
        );
    }

    /**
     * Splits a path into the parent's id and the new name.
     *
     * @return array{0: string|null, 1: string}
     */
    private function splitPath(string $path): array
    {
        $teile = explode('/', trim($path, '/'));
        $name = (string) array_pop($teile);

        if ($teile === []) {
            return [null, $name];
        }

        $eltern = $this->folderNamed(implode('/', $teile));

        if ($eltern === null) {
            throw new RuntimeException('Folder not found: '.implode('/', $teile));
        }

        return [$eltern['id'], $name];
    }

    /**
     * @return array<string, mixed>
     */
    private function folderOrFail(string $path): array
    {
        $ordner = $this->folderNamed($path);

        if ($ordner === null) {
            throw new RuntimeException("Folder not found: {$path}");
        }

        return $ordner;
    }

    /**
     * @param  array<string, mixed>  $folder
     */
    private function refuseSystemFolder(array $folder): void
    {
        if (SystemFolders::isProtected($folder['path'], $folder['name'], $folder['flags'])) {
            throw new RuntimeException('System folders cannot be renamed or deleted.');
        }
    }
}
