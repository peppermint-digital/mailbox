<?php

namespace Peppermint\Mailbox\Imap;

use DirectoryTree\ImapEngine\Mailbox as ImapEngineMailbox;
use DirectoryTree\ImapEngine\MailboxInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Contracts\TokenRefresher;
use Peppermint\Mailbox\Folders\FolderNames;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Search\Criteria;
use Peppermint\Mailbox\Threading\ThreadPage;
use RuntimeException;

/**
 * A mailbox, as a product uses one.
 *
 * This is the point of the package: install it, hand it an account, read mail.
 * Nothing above this class knows what IMAP is, and no product has to write the
 * connecting, the retrying, the folder arithmetic or the extraction again.
 *
 * ## Every call connects and disconnects
 *
 * Tempting to keep the connection open — and wrong for a web request. A held
 * IMAP connection outlives the request in the worker, and the next request on
 * the same worker inherits a socket whose server may have dropped it in the
 * meantime. The symptom is an error on an action nobody did anything wrong in.
 *
 * Cheap it is not; correct it is. Products that need several operations at
 * once pass them to {@see batch()}, which holds one connection for their
 * duration — and hands out this client, not the driver object underneath.
 *
 * ## The token is refreshed before connecting, not after failing
 *
 * An expired token gives an authentication error that looks exactly like a
 * wrong password. Refreshing first turns a confusing failure into no failure.
 */
class MailboxClient implements Mailbox
{
    /**
     * The connection a running {@see batch()} holds, or null.
     *
     * Not a cache: it lives for the duration of one batch and is dropped in
     * the same `finally` that disconnects it. A connection that outlives its
     * batch would be exactly the held socket the class comment warns about.
     */
    private mixed $open = null;

    public function __construct(
        private readonly MailAccount $account,
        private readonly ?TokenRefresher $refresher = null,
        private readonly ?RetryPolicy $retry = null,
        private readonly int $timeout = 120,
        /** Told about each retry, so the product can log in its own way. */
        private readonly ?\Closure $onRetry = null,
        /**
         * Builds the connection. Injectable so the whole client can be shown
         * without a server — and so a product with its own IMAP setup can
         * keep it.
         *
         * @var null|\Closure(array): MailboxInterface
         */
        private readonly ?\Closure $connector = null,
        /** Turns an IMAP message into rows; swappable for a product that needs more. */
        private readonly MessageFormatter $formatter = new MessageFormatter,
    ) {
        if (! $connector && ! class_exists(ImapEngineMailbox::class)) {
            throw new RuntimeException(
                'directorytree/imapengine is required to talk to a mailbox. '
                .'It is a suggest of peppermint/mailbox: install it in the product that reads mail.'
            );
        }
    }

    /**
     * Runs several operations over one connection.
     *
     * The callback is handed this client, not the mailbox object underneath —
     * a caller that receives the driver is tied to IMAP, and the whole point
     * of {@see Mailbox} is that it is not.
     *
     * Retries wrap the whole callback, because a retry means a new connection
     * and everything done on the old one is gone with it.
     *
     * @template T
     *
     * @param  callable(self): T  $work
     * @return T
     */
    public function batch(callable $work): mixed
    {
        return $this->session(fn (): mixed => $work($this));
    }

    /**
     * Opens a connection, runs the callback, closes it — with retries.
     *
     * Inside a running batch the open connection is reused and left open: the
     * batch opened it and the batch closes it.
     *
     * @template T
     *
     * @param  callable(mixed): T  $work
     * @return T
     */
    private function session(callable $work): mixed
    {
        if ($this->open !== null) {
            return $work($this->open);
        }

        $policy = $this->retry ?? new RetryPolicy;

        return $policy->run(function () use ($work) {
            $mailbox = $this->connect();
            $this->open = $mailbox;

            try {
                return $work($mailbox);
            } finally {
                $this->open = null;
                $mailbox->disconnect();
            }
        }, $this->onRetry);
    }

    /**
     * The folders of this mailbox, as plain rows.
     *
     * @return list<array{name: string, path: string, flags: list<string>}>
     */
    public function folders(): array
    {
        return $this->session(function ($mailbox): array {
            $rows = [];

            foreach ($mailbox->folders()->get() as $folder) {
                $rows[] = [
                    'name' => $folder->name(),
                    'path' => $folder->path(),
                    'flags' => array_map('strval', $folder->flags() ?? []),
                ];
            }

            return $rows;
        });
    }

    /**
     * Creates the standard folders this mailbox is missing.
     *
     * Best effort: a server that refuses one is not a reason to skip the rest.
     *
     * @return list<string> the paths actually created
     */
    public function ensureStandardFolders(): array
    {
        return $this->session(function ($mailbox): array {
            $rows = [];

            foreach ($mailbox->folders()->get() as $folder) {
                $rows[] = ['name' => $folder->name(), 'path' => $folder->path()];
            }

            $prefix = FolderPaths::inboxPrefix(array_column($rows, 'path'));
            $created = [];

            foreach (FolderPaths::missingStandardFolders($rows) as $kind) {
                foreach (FolderPaths::standardCandidates($kind, $prefix) as $path) {
                    try {
                        $folder = $mailbox->folders()->create($path);
                        $created[] = $folder->path();
                        break;
                    } catch (\Throwable) {
                        // This shape was refused; the next candidate may fit.
                        continue;
                    }
                }
            }

            return $created;
        });
    }

    public function createFolder(string $path): void
    {
        $this->session(fn ($mailbox) => $mailbox->folders()->create($path));
    }

    /**
     * Renames a folder, keeping it where it is.
     *
     * @throws RuntimeException when the folder is gone or is a system folder
     */
    public function renameFolder(string $path, string $newName): string
    {
        return $this->session(function ($mailbox) use ($path, $newName): string {
            $folder = $this->folderAt($mailbox, $path);
            $this->refuseSystemFolder($folder);

            $ziel = FolderPaths::renamed($folder->path(), $newName, $folder->delimiter() ?: '/');
            $folder->move($ziel);

            return $ziel;
        });
    }

    /** @throws RuntimeException when the folder is gone or is a system folder */
    public function deleteFolder(string $path): void
    {
        $this->session(function ($mailbox) use ($path): void {
            $folder = $this->folderAt($mailbox, $path);
            $this->refuseSystemFolder($folder);

            $folder->delete();
        });
    }

    /**
     * Kopfzeilen-Zeilen eines Ordners, plus wie viele es insgesamt sind.
     *
     * Bewusst OHNE Rumpf: Sortiert und geschnitten wird auf diesen Zeilen, und
     * erst die sichtbare Seite wird ausformatiert. Alle hundert zu formatieren,
     * um fuenfundzwanzig zu zeigen, sind fuenfundsiebzig Rumpf-Abrufe, die
     * niemand wollte.
     *
     * Mehrere Ordner ueber dieselbe Verbindung: in ein {@see batch()} packen.
     * Frueher nahm diese Methode das Postfach-Objekt entgegen — das war der
     * einzige Grund, warum ein Produkt es ueberhaupt in die Hand bekam, und
     * damit der einzige Grund, warum es IMAP kennen musste.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function headerRows(string $folder, int $limit = MessagePage::FETCH_LIMIT): array
    {
        return $this->session(function ($mailbox) use ($folder, $limit): array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $ordner) {
                return [[], 0];
            }

            $abfrage = $ordner->messages()->newest();
            $gesamt = $abfrage->count();

            $zeilen = [];

            foreach ($abfrage->withHeaders()->withFlags()->limit($limit)->get() as $nachricht) {
                $zeilen[] = $this->formatter->summary($nachricht);
            }

            return [$zeilen, $gesamt];
        });
    }

    /**
     * One page of a folder's message list.
     *
     * Der ganze Ablauf an einer Stelle — und in der Reihenfolge, die zaehlt:
     * hundert Zeilen OHNE Rumpf holen, sortieren, im Gesendet-Ordner
     * entdoppeln, schneiden, und erst die sichtbaren formatieren.
     *
     * Vorher stand genau das in jedem Produkt einzeln, hinter einem
     * `headerRows()`, das alle hundert formatierte — und damit ueber IMAP
     * hundert Rumpf-Abrufe ausloeste. Siehe Bug #877.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function page(string $folder, int $page = 1, int $perPage = 25): array
    {
        return $this->session(function ($mailbox) use ($folder, $page, $perPage): array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $ordner) {
                return [[], 0];
            }

            $abfrage = $ordner->messages()->newest();
            $gesamt = $abfrage->count();

            $zeilen = MessagePage::sortByDateDesc($this->cheapRows(
                $abfrage->withHeaders()->withFlags()->limit(MessagePage::FETCH_LIMIT)->get()
            ));

            if (MessagePage::isSentFolder($ordner->path())) {
                // Viele Server legen beim Senden selbst eine Kopie ab, waehrend
                // der Client seine eigene anhaengt.
                $zeilen = MessagePage::dedupe($zeilen);
            }

            $effektiv = MessagePage::effectiveTotal($gesamt, count($zeilen));

            return [
                array_map(
                    fn (array $zeile): array => $this->formatter->summary($zeile['message']),
                    MessagePage::slice($zeilen, $page, $perPage),
                ),
                $effektiv,
            ];
        });
    }

    /**
     * One page of conversations.
     *
     * The window is the same hundred rows the flat list uses — and they are
     * fetched WITHOUT bodies here. Grouping needs headers and flags, nothing
     * else; only the newest message of each visible chain is formatted in
     * full afterwards.
     *
     * That is not a detail. Formatting a row touches its body, and over IMAP a
     * body that was not loaded is fetched on the spot: a hundred formatted rows
     * are a hundred round trips for the twenty-five a person sees. Measured in
     * the Manager on 11.06.2026, where it cost roughly four times the load.
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
        return $this->session(function ($mailbox) use ($folder, $page, $perPage, $ownReplies, $excludeThreadIds): array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $ordner) {
                return [[], 0];
            }

            $zeilen = $this->cheapRows(
                $ordner->messages()->newest()->withHeaders()->withFlags()->limit(MessagePage::FETCH_LIMIT)->get()
            );

            return ThreadPage::of(
                $zeilen,
                $ownReplies,
                $page,
                $perPage,
                $excludeThreadIds,
                // Erst hier wird der Rumpf angefasst — für die eine Nachricht
                // je sichtbarer Kette, die in der Liste steht.
                fn (array $zeile): array => isset($zeile['message'])
                    ? $this->formatter->summary($zeile['message'])
                    : array_diff_key($zeile, ['message' => null]),
            );
        });
    }

    /**
     * Header rows without touching a single body.
     *
     * Each row keeps its message object so the page that ends up visible can
     * still be formatted in full. It never leaves the package: ThreadPage
     * strips it before the rows go out.
     *
     * @param  iterable<mixed>  $messages
     * @return list<array<string, mixed>>
     */
    private function cheapRows(iterable $messages): array
    {
        $zeilen = [];

        foreach ($messages as $nachricht) {
            $von = $nachricht->from();

            $zeilen[] = [
                'message' => $nachricht,
                'uid' => $nachricht->uid(),
                'message_id' => $nachricht->messageId(),
                'subject' => $nachricht->subject(),
                'date' => $nachricht->date()?->toIso8601String(),
                'from_address' => $von?->email() ?? '',
                'from_name' => $von?->name() ?? '',
                'is_read' => $nachricht->isSeen(),
                'is_flagged' => $nachricht->isFlagged(),
                // Kostet nichts extra: mit withHeaders() sind sie schon da —
                // und ohne sie lässt sich nichts zu Ketten gruppieren.
                'in_reply_to' => $nachricht->header('in-reply-to')?->getValue(),
                'references' => $nachricht->header('references')?->getValue(),
            ];
        }

        return $zeilen;
    }

    /**
     * Search one folder.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function search(string $folder, Criteria $criteria, int $limit = 50): array
    {
        $this->refuseEmpty($criteria);

        return $this->session(function ($mailbox) use ($folder, $criteria, $limit): array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $ordner) {
                return [[], 0];
            }

            $zeilen = $this->hits($ordner, $criteria, $limit);

            return [MessagePage::sortByDateDesc($zeilen), count($zeilen)];
        });
    }

    /**
     * Search every folder worth searching.
     *
     * One folder at a time, because that is all IMAP offers — and a folder
     * that refuses must not end the search. A locked or vanished folder is
     * skipped and counted out, not raised: someone looking for an invoice
     * would rather see nine folders' worth than an error.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    public function searchAll(Criteria $criteria, int $limit = 50, int $perFolder = 10): array
    {
        $this->refuseEmpty($criteria);

        return $this->session(function ($mailbox) use ($criteria, $limit, $perFolder): array {
            $treffer = [];
            $durchsucht = 0;

            foreach ($mailbox->folders()->get() as $ordner) {
                if ($this->excludedFromSearch($ordner)) {
                    continue;
                }

                try {
                    foreach ($this->hits($ordner, $criteria, $perFolder) as $zeile) {
                        $zeile['folder'] = $ordner->path();
                        $treffer[] = $zeile;
                    }

                    $durchsucht++;
                } catch (\Throwable $e) {
                    // A folder that locks itself must not end the search — but
                    // it must not vanish either: it is missing from the count,
                    // and the count is on screen.
                    Log::warning('Folder skipped during search', [
                        'folder' => $ordner->path(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $treffer = MessagePage::sortByDateDesc($treffer);

            return [array_slice($treffer, 0, $limit), count($treffer), $durchsucht];
        });
    }

    /**
     * The rows one folder yields for this search.
     *
     * `withHeaders()` is not optional: without it every hit comes back without
     * a subject and without a date. Measured at a real mailbox, where every
     * result read "(no subject)".
     *
     * @return list<array<string, mixed>>
     */
    private function hits(mixed $ordner, Criteria $criteria, int $limit): array
    {
        $abfrage = $ordner->messages()->newest();
        $criteria->applyToImapQuery($abfrage);

        $zeilen = [];

        foreach ($abfrage->withHeaders()->withFlags()->limit($limit)->get() as $nachricht) {
            $zeilen[] = $this->formatter->summary($nachricht);
        }

        return $zeilen;
    }

    /**
     * Does this folder stay out of a search across everything?
     *
     * The special-use FLAG decides, not the name: Office 365 hands folder
     * names over in IMAP's modified UTF-7 (`Gel&APY-schte Elemente`), where a
     * comparison against "deleted" finds nothing. Measured at a real mailbox:
     * 10 of 50 hits came out of the trash before this went by the flags.
     *
     * The name list stays as a fallback for servers without special-use — it
     * lives in {@see FolderNames} and knows the encoded spellings.
     */
    private function excludedFromSearch(mixed $ordner): bool
    {
        try {
            foreach ((array) $ordner->flags() as $flag) {
                $kennzeichen = mb_strtolower((string) $flag);

                foreach (['trash', 'junk', 'drafts'] as $unerwuenscht) {
                    if (str_contains($kennzeichen, $unerwuenscht)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            // Server without special-use: the name decides below.
        }

        return FolderNames::isExcludedFromSearch((string) $ordner->path());
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
     * The path of a standard folder — by flag first, by name only after.
     */
    public function specialFolder(string $kind): ?string
    {
        return $this->session(function ($mailbox) use ($kind): ?string {
            $kennzeichen = '\\'.$kind;
            $ueberName = null;

            foreach ($mailbox->folders()->get() as $ordner) {
                foreach ((array) ($ordner->flags() ?? []) as $flag) {
                    if (mb_strtolower((string) $flag) === mb_strtolower($kennzeichen)) {
                        return $ordner->path();
                    }
                }

                // Gemerkt, nicht genommen: Ein markierter Ordner weiter unten
                // in der Liste ist die bessere Antwort als ein passender Name.
                if ($ueberName === null && FolderNames::looksLike((string) $ordner->path(), $kind)) {
                    $ueberName = $ordner->path();
                }
            }

            return $ueberName;
        });
    }

    /**
     * How many messages are newer than a point in time.
     */
    public function countNewSince(string $folder, \DateTimeInterface $since, int $scan = 50): int
    {
        return $this->session(function ($mailbox) use ($folder, $since, $scan): int {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $ordner) {
                return 0;
            }

            $anzahl = 0;

            // Ohne Rumpf und ohne Formatierung: gezaehlt wird an der
            // Kopfzeile, und die ist mit withHeaders() schon da.
            foreach ($ordner->messages()->newest()->withHeaders()->limit($scan)->get() as $nachricht) {
                $datum = $nachricht->date();

                if ($datum && $datum->getTimestamp() > $since->getTimestamp()) {
                    $anzahl++;
                }
            }

            return $anzahl;
        });
    }

    /**
     * Moves several messages into the archive folder.
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

        return $this->batch(function (self $postfach) use ($folder, $uids): array {
            $ziel = $postfach->specialFolder('Archive');

            if ($ziel === null) {
                return ['archived' => 0, 'failed' => count($uids)];
            }

            if ($ziel === $folder) {
                // Schon da, wo sie hingehoeren. Das als Fehlschlag zu zaehlen
                // liesse einen zweiten Klick kaputt aussehen.
                return ['archived' => count($uids), 'failed' => 0];
            }

            $geschafft = 0;

            foreach ($uids as $uid) {
                if ($postfach->move($folder, $uid, $ziel)) {
                    $geschafft++;
                }
            }

            return ['archived' => $geschafft, 'failed' => count($uids) - $geschafft];
        });
    }

    /**
     * Finds messages whose Message-ID carries this token.
     *
     * @return list<int|string>
     */
    public function findByToken(string $folder, string $token, int $limit = 20): array
    {
        return $this->session(function ($mailbox) use ($folder, $token, $limit): array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $ordner) {
                return [];
            }

            $treffer = [];

            foreach ($ordner->messages()->newest()->text($token)->withHeaders()->limit($limit)->get() as $nachricht) {
                // Die Volltextsuche ist alles, was IMAP hat — sie trifft auch
                // eine Mail, die den Token nur zitiert. Entschieden wird an
                // der Message-ID.
                $messageId = $nachricht->messageId();

                if ($messageId !== null && str_contains($messageId, $token)) {
                    $treffer[] = $nachricht->uid();
                }
            }

            return $treffer;
        });
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
            // Wortlaut des Servers, nicht unsere Auslegung davon.
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Eine einzelne Nachricht, vollstaendig.
     *
     * Null, wenn sie nicht (mehr) da ist — ein geteiltes Postfach aendert sich,
     * waehrend jemand hineinsieht.
     */
    public function message(string $folder, int|string $uid): ?array
    {
        return $this->session(function ($mailbox) use ($folder, $uid): ?array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());
            $nachricht = $ordner?->messages()->withHeaders()->withFlags()->withBody()->find((int) $uid);

            if (! $nachricht) {
                return null;
            }

            return $this->formatter->full($nachricht);
        });
    }

    /**
     * One attachment, with its bytes.
     *
     * The message view lists attachments with name, type and size — enough to
     * show them, not enough to keep them. A product that wants to file one
     * somewhere needs the contents, and without this every product writes the
     * same IMAP round-trip.
     *
     * Identified by POSITION, not by name: Two attachments in one mail may
     * carry the same filename, and some carry none at all. The position is what
     * the message view already hands out.
     *
     * Inline images are skipped here exactly as they are in the view — what is
     * counted as attachment number two on screen has to be attachment number
     * two here, or people file the wrong thing.
     *
     * @return array{filename: string, mime_type: string, contents: string}|null
     *                                                                           null when the message or the position is gone — a mailbox is
     *                                                                           shared and things move.
     */
    public function attachment(string $folder, int|string $uid, int $index): ?array
    {
        return $this->session(function ($mailbox) use ($folder, $uid, $index): ?array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());
            $nachricht = $ordner?->messages()->withHeaders()->withBody()->find((int) $uid);

            if (! $nachricht) {
                return null;
            }

            $gefunden = $this->formatter->attachmentAt($nachricht, $index);

            return $gefunden;
        });
    }

    /**
     * Marks a message read or unread.
     *
     * Returns false when the message is not there any more, rather than
     * throwing: a mailbox is shared and things move. Someone clicking "mark
     * read" on a mail a colleague just filed should see nothing happen, not an
     * error about a uid.
     */
    /**
     * Every attachment of a message, with its bytes.
     *
     * @return list<array{filename: string, mime_type: string|null, contents: string}>
     */
    public function attachments(string $folder, int|string $uid): array
    {
        return $this->session(function ($mailbox) use ($folder, $uid): array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());
            $nachricht = $ordner?->messages()->withHeaders()->withBody()->find((int) $uid);

            if (! $nachricht) {
                return [];
            }

            $anhaenge = [];

            foreach ($nachricht->attachments() as $anhang) {
                $typ = (string) ($anhang->contentType() ?? '');

                if (MessageFormatter::isEmbeddedImage($anhang->contentId(), $anhang->contentDisposition(), $typ)) {
                    continue;
                }

                $anhaenge[] = [
                    'filename' => $anhang->filename() ?? 'attachment',
                    'mime_type' => $anhang->contentType(),
                    'contents' => (string) $anhang->contents(),
                ];
            }

            return $anhaenge;
        });
    }

    /**
     * Puts a message into a folder, without sending it.
     *
     * @param  list<string>  $flags
     */
    public function append(string $folder, string $raw, array $flags = []): int|string|null
    {
        return $this->session(function ($mailbox) use ($folder, $raw, $flags): int|string|null {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $ordner) {
                throw new RuntimeException("Folder not found: {$folder}");
            }

            $ergebnis = $ordner->messages()->append($raw, $flags);

            // Manche Server melden die neue uid, andere nicht. Beides ist in
            // Ordnung — abgelegt ist sie so oder so.
            return is_int($ergebnis) || is_string($ergebnis) ? $ergebnis : null;
        });
    }

    public function setSeen(string $folder, int|string $uid, bool $seen): bool
    {
        return $this->onMessage($folder, $uid, function ($message) use ($seen): bool {
            $seen ? $message->markSeen() : $message->unmarkSeen();

            return true;
        });
    }

    /** Sets or clears the flag. Returns false when the message is gone. */
    public function setFlagged(string $folder, int|string $uid, bool $flagged): bool
    {
        return $this->onMessage($folder, $uid, function ($message) use ($flagged): bool {
            $flagged ? $message->markFlagged() : $message->unmarkFlagged();

            return true;
        });
    }

    /**
     * Moves a message into another folder.
     *
     * Moving into the folder it already sits in is answered with true without
     * asking the server: it is not an error, nothing needs to happen, and some
     * servers refuse it in a way that reads like a real failure.
     */
    public function move(string $from, int|string $uid, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return $this->session(function ($mailbox) use ($from, $uid, $to): bool {
            $quelle = FolderResolver::resolve($mailbox->folders()->get(), $from, fn ($f) => $f->path(), fn ($f) => $f->name());
            $ziel = FolderResolver::resolve($mailbox->folders()->get(), $to, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $quelle || ! $ziel) {
                return false;
            }

            $message = $quelle->messages()->find((int) $uid);

            if (! $message) {
                return false;
            }

            $message->move($ziel->path(), true);

            return true;
        });
    }

    /**
     * Deletes a message — into the trash where there is one.
     *
     * "Delete" in a mail client means "put it where I can get it back". Only
     * when there is no trash folder, or the message is already in it, does the
     * message actually go. Deleting outright from the inbox would be a
     * different promise than the button makes.
     *
     * @param  list<string>  $trashNames  lowercase aliases of the trash folder
     */
    public function delete(string $folder, int|string $uid, array $trashNames = ['trash', 'papierkorb', 'deleted items', 'gelöschte elemente']): bool
    {
        return $this->session(function ($mailbox) use ($folder, $uid, $trashNames): bool {
            $quelle = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $quelle) {
                return false;
            }

            $message = $quelle->messages()->find((int) $uid);

            if (! $message) {
                return false;
            }

            $papierkorb = null;

            foreach ($mailbox->folders()->get() as $kandidat) {
                if (FolderPaths::matchesAny($kandidat->name(), $kandidat->path(), $trashNames)) {
                    $papierkorb = $kandidat->path();
                    break;
                }
            }

            if ($papierkorb && $papierkorb !== $quelle->path()) {
                $message->move($papierkorb, true);
            } else {
                // Already in the trash, or there is none: now it really goes.
                $message->delete(true);
            }

            return true;
        });
    }

    /**
     * Runs something on one message, or answers false if it is not there.
     *
     * @param  callable(mixed): bool  $work
     */
    private function onMessage(string $folder, int|string $uid, callable $work): bool
    {
        return $this->session(function ($mailbox) use ($folder, $uid, $work): bool {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());
            $message = $ordner?->messages()->find((int) $uid);

            if (! $message) {
                return false;
            }

            return $work($message);
        });
    }

    /**
     * Opens the connection.
     *
     * Deliberately untyped: a product may bring its own mailbox object, and
     * tests bring one that never touches a network. What the object has to do
     * is defined by the calls in this class, not by a class name.
     */
    private function connect(): mixed
    {
        // Before connecting, not after failing: an expired token looks exactly
        // like a wrong password from the outside.
        if ($this->refresher && $this->account->usesOAuth() && $this->account->isTokenExpiringSoon()) {
            $this->refresher->ensureFresh($this->account);
        }

        $settings = ConnectionSettings::for($this->account, $this->timeout)->values;

        return $this->connector ? ($this->connector)($settings) : ImapEngineMailbox::make($settings);
    }

    private function folderAt($mailbox, string $path): mixed
    {
        $folder = FolderResolver::resolve(
            $mailbox->folders()->get(),
            $path,
            fn ($f) => $f->path(),
            fn ($f) => $f->name(),
        );

        if (! $folder) {
            throw new RuntimeException("Folder not found: {$path}");
        }

        return $folder;
    }

    private function refuseSystemFolder($folder): void
    {
        $flags = array_map('strval', $folder->flags() ?? []);

        if (SystemFolders::isProtected($folder->path(), $folder->name(), $flags)) {
            throw new RuntimeException('System folders cannot be renamed or deleted.');
        }
    }
}
