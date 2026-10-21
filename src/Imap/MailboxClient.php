<?php

namespace Peppermint\Mailbox\Imap;

use DirectoryTree\ImapEngine\Mailbox as ImapEngineMailbox;
use DirectoryTree\ImapEngine\MailboxInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Peppermint\Mailbox\Content\RohFassung;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Contracts\TokenRefresher;
use Peppermint\Mailbox\Folders\FolderNames;
use Peppermint\Mailbox\Index\Kopfzeilenverzeichnis;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Search\Criteria;
use Peppermint\Mailbox\Support\Betreff;
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
        /**
         * Was in einer Kennung steht, ohne den Server zu fragen (#6119).
         *
         * Injizierbar, weil ein Test sehen will, WAS abgefragt wird — und weil
         * ein Produkt mit eigener Ablage seine eigene mitbringen kann.
         */
        private readonly Kopfzeilenverzeichnis $verzeichnis = new Kopfzeilenverzeichnis,
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

            foreach (self::fuerListenzeilen($abfrage)->limit($limit)->get() as $nachricht) {
                $zeilen[] = $this->formatter->summary($nachricht);
            }

            return [$zeilen, $gesamt];
        });
    }

    /**
     * One page of a folder's message list.
     *
     * Der ganze Ablauf an einer Stelle — und in der Reihenfolge, die zaehlt:
     * Belegung vom Server holen, Zeilen aus dem Verzeichnis nehmen und nur
     * Fehlendes nachholen, sortieren, im Gesendet-Ordner entdoppeln,
     * schneiden, und erst fuer die sichtbaren Struktur und Flags holen.
     *
     * Vorher stand genau das in jedem Produkt einzeln, hinter einem
     * `headerRows()`, das alle hundert formatierte — und damit ueber IMAP
     * hundert Rumpf-Abrufe ausloeste. Siehe Bug #877.
     *
     * ## Was hier gegen den Server geht, und was nicht
     *
     * Gemessen am 20.10.2026 an `department@peppermint-digital.de`, 56
     * Nachrichten, eine Seite von 25, am laufenden Brain:
     *
     *     ohne Verzeichnis      2.045 ms
     *     kalter Ordner         2.168 ms
     *     warmer Ordner       520–605 ms
     *
     * Vom Server kommen IMMER: die Belegung (welche Kennungen liegen im
     * Ordner) und die Flags der sichtbaren Zeilen. Aus dem Verzeichnis kommt
     * nur, was sich fuer eine Kennung nicht mehr aendern KANN. Deshalb kann
     * diese Liste nicht veralten — siehe {@see Kopfzeilenverzeichnis}.
     *
     * `headerRows()` eine Methode weiter oben laeuft weiter ueber den lebenden
     * Weg: Es ist nicht der heisse Pfad, und zwei Wege zu pflegen, von denen
     * einer selten benutzt wird, ist teurer als die Sekunde, die er kostet.
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

            ['gesamt' => $gesamt, 'gueltigkeit' => $gueltigkeit] = $this->ordnerzustand($ordner);

            /*
             * Die BELEGUNG kommt immer vom Server — das ist die Invariante des
             * Verzeichnisses (#6119). Ein reiner UID-Abruf kostet 69 ms und
             * kann nichts verschweigen: Was angekommen ist, steht darin; was
             * gelöscht wurde, nicht.
             */
            $kennungen = [];

            foreach ($ordner->messages()->newest()->withoutHeaders()->withoutFlags()->withoutBody()->limit(MessagePage::FETCH_LIMIT)->get() as $nachricht) {
                $kennungen[] = $nachricht->uid();
            }

            if ($kennungen === []) {
                return [[], $gesamt];
            }

            $zeilen = $this->fensterZeilen($ordner, $kennungen, $gueltigkeit);

            $zeilen = MessagePage::sortByDateDesc($zeilen);

            if (MessagePage::isSentFolder($ordner->path())) {
                // Viele Server legen beim Senden selbst eine Kopie ab, waehrend
                // der Client seine eigene anhaengt.
                $zeilen = MessagePage::dedupe($zeilen);
            }

            $sichtbar = MessagePage::slice($zeilen, $page, $perPage);

            return [
                $this->sichtbareZeilen($ordner, $sichtbar, $gueltigkeit),
                MessagePage::effectiveTotal($gesamt, count($zeilen)),
            ];
        });
    }

    /**
     * Wie viele Nachrichten, und unter welcher Gueltigkeit.
     *
     * `status()` statt `count()`: Es ist EIN Befehl wie vorher, kostet aber die
     * Haelfte (32 statt 70 ms, gemessen 20.10.2026 an `department@`) und bringt
     * die `UIDVALIDITY` mit — ohne die waere das Verzeichnis nicht sicher.
     *
     * ## Warum es trotzdem einen Rueckweg gibt
     *
     * Weil die Liste damit von einem Befehl abhaengt, von dem sie vorher nicht
     * abhing. `STATUS` ist in RFC 3501 Pflicht und alle unsere Postfaecher
     * koennen es — aber „alle unsere" ist eine Aussage ueber heute. Ein Server,
     * der es verweigert, soll eine langsamere Liste bekommen, keine fehlende.
     *
     * Ohne Gueltigkeit wird das Verzeichnis nicht gelesen: `bekannt()` gibt
     * dann nur Zeilen heraus, die selbst ohne Gueltigkeit verzeichnet wurden.
     * Im Zweifel lieber den Server fragen.
     *
     * @return array{gesamt: int, gueltigkeit: int|null}
     */
    private function ordnerzustand(mixed $ordner): array
    {
        try {
            $werte = $ordner->status();

            return [
                'gesamt' => isset($werte['MESSAGES']) ? (int) $werte['MESSAGES'] : 0,
                'gueltigkeit' => isset($werte['UIDVALIDITY']) ? (int) $werte['UIDVALIDITY'] : null,
            ];
        } catch (\Throwable $fehler) {
            Log::warning('Mailbox: STATUS nicht moeglich — die Liste zaehlt selbst und laeuft ohne Verzeichnis. '.$fehler->getMessage());

            return ['gesamt' => (int) $ordner->messages()->count(), 'gueltigkeit' => null];
        }
    }

    /**
     * Die Zeilen des Fensters — aus dem Verzeichnis, Fehlendes vom Server.
     *
     * Das Fenster dient dem Sortieren nach Datum und dem Entdoppeln im
     * Gesendet-Ordner. Dafuer braucht es Datum, Message-ID, Betreff und
     * Absender — und genau die stehen im Verzeichnis, weil sie sich fuer eine
     * gegebene Kennung nicht mehr aendern koennen.
     *
     * Ein kalter Ordner kostet hier genau so viel wie vorher: Die Kopfzeilen
     * der unbekannten Kennungen werden in EINEM Abruf geholt. Ein warmer kostet
     * nichts.
     *
     * @param  list<int|string>  $kennungen
     * @return list<array<string, mixed>>
     */
    private function fensterZeilen(mixed $ordner, array $kennungen, ?int $gueltigkeit): array
    {
        $konto = (int) $this->account->id;
        $pfad = (string) $ordner->path();

        $bekannt = $this->verzeichnis->bekannt($konto, $pfad, $gueltigkeit, $kennungen);

        if ($bekannt === []) {
            // Entweder nie gesehen oder die Gueltigkeit hat gewechselt. Im
            // zweiten Fall liegen hier Zeilen, die nie wieder gelesen werden.
            $this->verzeichnis->aufraeumen($konto, $pfad, $gueltigkeit);
        }

        $fehlende = array_values(array_filter(
            $kennungen,
            static fn (int|string $kennung): bool => ! isset($bekannt[(string) $kennung]),
        ));

        if ($fehlende !== []) {
            $frisch = $this->kopfzeilenHolen($ordner, $fehlende);

            $this->verzeichnis->merkenKopf($konto, $pfad, $gueltigkeit, $frisch);

            foreach ($frisch as $zeile) {
                $bekannt[(string) $zeile['uid']] = $zeile;
            }

            $this->karteileichen($konto, $pfad, $gueltigkeit, $kennungen);
        }

        $zeilen = [];

        foreach ($kennungen as $kennung) {
            if (! isset($bekannt[(string) $kennung])) {
                continue;
            }

            $zeile = $bekannt[(string) $kennung];

            /*
             * Die Kennung in der Form des SERVERS, nicht in der der Spalte.
             * Die Spalte ist varchar (weil JMAP Zeichenketten vergibt), und
             * eine verzeichnete Zeile kam deshalb als `'5'` heraus, eine frisch
             * geholte als `5`. Die Oberflaeche vergleicht Kennungen — eine
             * Liste aus beiden Quellen haette Zeilen gehabt, die sich nicht
             * auswaehlen lassen. Ein Test hat genau das gefunden.
             */
            $zeile['uid'] = $kennung;

            $zeilen[] = $zeile;
        }

        return $zeilen;
    }

    /**
     * Verzeichnete Kennungen wegräumen, die der Server nicht mehr nennt.
     *
     * ## Nur im Bereich des Fensters, und das ist der ganze Trick
     *
     * Das Fenster umfasst die hundert jüngsten Kennungen. Für DIESEN Bereich
     * ist es maßgeblich: Was der Server hier nicht nennt, gibt es nicht mehr.
     * Was darunter liegt, ist nicht tot, sondern nur außerhalb — eine Regel
     * „lösche, was nicht im Fenster steht" würde bei jedem Blick in einen
     * großen Ordner das Verzeichnis aller älteren Nachrichten leeren.
     *
     * Gemessen am 21.10.2026 an `department@`: 65 verzeichnete Zeilen für 30
     * vorhandene Nachrichten. Gelesen werden die Toten nie — die Belegung kommt
     * immer vom Server —, aber sie wachsen für immer.
     *
     * ## Warum nur, wenn etwas Neues dazukam
     *
     * Der Aufrufer ruft das hier nur, wenn Kennungen FEHLTEN. Dann hat sich der
     * Ordner seit dem letzten Blick verändert, und nur dann lohnt die zusätzliche
     * Abfrage. Eine Löschung ohne Zugang hinterlässt damit ihre Leiche bis zur
     * nächsten neuen Nachricht — das ist der Preis für eine Abfrage, die im
     * Regelfall ausbleibt.
     *
     * ## Warum der Vergleich in PHP steht und was der Cast wirklich tut
     *
     * In PHP, weil die Spalte `varchar` ist (JMAP vergibt Zeichenketten) und
     * ein numerischer Vergleich in SQL ein `CAST` bräuchte, das in SQLite und
     * MySQL verschieden heißt.
     *
     * Der Cast auf `int` ist NICHT dafür da, zwei Zahlen-Zeichenketten richtig
     * zu ordnen — das tut PHP 8 von selbst (`'7' < 100` ist numerisch). Eine
     * Mutationsprobe hat das gezeigt: Den Cast zu entfernen liess keinen Test
     * umfallen, und mein Kommentar behauptete hier zuerst das Gegenteil.
     *
     * Was er wirklich leistet: Eine NICHT-numerische Kennung (`cqiaaaaus`)
     * wird zu 0 und liegt damit immer unter der Untergrenze — sie wird also
     * nie weggeräumt. Ohne den Cast verglichen PHP 8 sie als Text mit `'100'`,
     * `'cqiaaaaus' < '100'` ist falsch, und die Zeile waere gelöscht worden.
     * Dieses Verzeichnis bedient heute nur IMAP; die Spalte ist trotzdem breit,
     * und dann muss auch der Vergleich sie ertragen.
     *
     * @param  list<int|string>  $kennungen  die Belegung des Fensters
     */
    private function karteileichen(int $konto, string $pfad, ?int $gueltigkeit, array $kennungen): void
    {
        $zahlen = array_map('intval', $kennungen);

        if ($zahlen === []) {
            return;
        }

        /*
         * Ist das Fenster NICHT voll, ist es die ganze Belegung des Ordners —
         * dann gibt es kein „aelter als das Fenster", und alles, was der Server
         * nicht nennt, ist tot. Das ist der Regelfall: Die meisten Ordner haben
         * weniger als hundert Nachrichten.
         *
         * Ein Test hat diese Unterscheidung erzwungen. Ohne sie blieb eine
         * Kennung stehen, die kleiner war als die kleinste des Fensters,
         * obwohl der Server sie nicht mehr nannte und das Fenster vollstaendig
         * war.
         */
        $vollesFenster = count($kennungen) >= MessagePage::FETCH_LIMIT;
        $untergrenze = $vollesFenster ? min($zahlen) : 0;
        $lebende = array_map('strval', $kennungen);

        $tote = [];

        foreach ($this->verzeichnis->kennungen($konto, $pfad, $gueltigkeit) as $verzeichnet) {
            if ($vollesFenster && (int) $verzeichnet < $untergrenze) {
                // Aelter als das Fenster — darueber sagt ein VOLLES Fenster
                // nichts.
                continue;
            }

            if (! in_array($verzeichnet, $lebende, true)) {
                $tote[] = $verzeichnet;
            }
        }

        if ($tote === []) {
            return;
        }

        $weg = $this->verzeichnis->vergessenKennungen($konto, $pfad, $tote);

        if ($weg > 0) {
            Log::info("Mailbox: {$weg} verzeichnete Kennungen weggeraeumt, die der Server nicht mehr nennt ({$pfad}).");
        }
    }

    /**
     * Kopfzeilen fuer unbekannte Kennungen — in EINEM Abruf, ohne Struktur.
     *
     * Der Betreff wird hier schon lesbar gemacht und so auch verzeichnet. Sonst
     * benutzte die Entdoppelung je nach Herkunft der Zeile einmal die rohe und
     * einmal die lesbare Form als Schluessel — derselbe Fehler wie bei der
     * Drahtform, nur eine Ebene tiefer.
     *
     * @param  list<int|string>  $kennungen
     * @return list<array<string, mixed>>
     */
    private function kopfzeilenHolen(mixed $ordner, array $kennungen): array
    {
        $zeilen = [];

        try {
            foreach (self::fuerKopfzeilen($ordner->messages()->uid($kennungen))->get() as $nachricht) {
                $von = $nachricht->from();

                $zeilen[] = [
                    'uid' => $nachricht->uid(),
                    'message_id' => $nachricht->messageId(),
                    'subject' => Betreff::lesbar($nachricht->subject()),
                    'from_address' => $von?->email() ?? '',
                    'from_name' => $von?->name() ?? '',
                    'date' => $nachricht->date()?->toIso8601String(),
                    'in_reply_to' => $nachricht->header('in-reply-to')?->getValue(),
                    'references' => $nachricht->header('references')?->getValue(),
                ];
            }
        } catch (\Throwable $fehler) {
            Log::warning('Mailbox: Kopfzeilen des Fensters nicht geholt — die Seite bleibt leer. '.$fehler->getMessage());
        }

        return $zeilen;
    }

    /**
     * Die sichtbaren Zeilen fertigstellen: Anhangzahl und Flags.
     *
     * Zwei Abrufe, jeder so gross wie noetig:
     *
     *   - BODYSTRUCTURE nur fuer Zeilen OHNE verzeichnete Anhangzahl. Das ist
     *     der teuerste Abruf ueberhaupt (2,2 bis 13 s fuer 54 Zeilen); im
     *     warmen Zustand bleibt er aus.
     *   - FLAGS fuer ALLE sichtbaren, immer. Lesemarken aendert jeder andere
     *     Client jederzeit — eine verzeichnete Lesemarke waere die Art Fehler,
     *     die niemand als Fehler erkennt.
     *
     * @param  list<array<string, mixed>>  $sichtbar
     * @return list<array<string, mixed>>
     */
    private function sichtbareZeilen(mixed $ordner, array $sichtbar, ?int $gueltigkeit): array
    {
        $kennungen = array_values(array_filter(array_map(
            static fn (array $zeile): mixed => $zeile['uid'] ?? null,
            $sichtbar,
        )));

        if ($kennungen === []) {
            return [];
        }

        $ohneZahl = array_values(array_filter(
            $kennungen,
            static function (int|string $kennung) use ($sichtbar): bool {
                foreach ($sichtbar as $zeile) {
                    if ((string) ($zeile['uid'] ?? '') === (string) $kennung) {
                        return ($zeile['attachment_count'] ?? null) === null;
                    }
                }

                return true;
            },
        ));

        $zahlen = $this->anhangzahlenHolen($ordner, $ohneZahl);

        if ($zahlen !== []) {
            $this->verzeichnis->merkenAnhangzahl(
                (int) $this->account->id,
                (string) $ordner->path(),
                $gueltigkeit,
                $zahlen,
            );
        }

        $flaggen = $this->flaggenHolen($ordner, $kennungen);

        return array_map(
            function (array $zeile) use ($zahlen, $flaggen): array {
                $kennung = (string) ($zeile['uid'] ?? '');

                return $this->formatter->zeile(array_merge($zeile, [
                    'attachment_count' => $zahlen[$kennung] ?? $zeile['attachment_count'] ?? 0,
                    'is_read' => $flaggen[$kennung]['is_read'] ?? true,
                    'is_flagged' => $flaggen[$kennung]['is_flagged'] ?? false,
                ]));
            },
            $sichtbar,
        );
    }

    /**
     * @param  list<int|string>  $kennungen
     * @return array<string, int>
     */
    private function anhangzahlenHolen(mixed $ordner, array $kennungen): array
    {
        if ($kennungen === []) {
            return [];
        }

        $zahlen = [];

        try {
            foreach ($ordner->messages()->uid($kennungen)->withBodyStructure()->get() as $nachricht) {
                $zahlen[(string) $nachricht->uid()] = $this->formatter->anhangZahl($nachricht);
            }
        } catch (\Throwable $fehler) {
            Log::warning('Mailbox: Struktur der sichtbaren Zeilen nicht geholt — die Anhangzahl bleibt 0. '.$fehler->getMessage());
        }

        return $zahlen;
    }

    /**
     * @param  list<int|string>  $kennungen
     * @return array<string, array{is_read: bool, is_flagged: bool}>
     */
    private function flaggenHolen(mixed $ordner, array $kennungen): array
    {
        $flaggen = [];

        try {
            foreach ($ordner->messages()->uid($kennungen)->withFlags()->get() as $nachricht) {
                $flaggen[(string) $nachricht->uid()] = [
                    'is_read' => $nachricht->isSeen(),
                    'is_flagged' => $nachricht->isFlagged(),
                ];
            }
        } catch (\Throwable $fehler) {
            /*
             * Lieber alles als gelesen zeigen als die Seite verlieren: Eine
             * falsche Lesemarke ist aergerlich, eine leere Liste ist ein
             * Ausfall. Die Warnung macht den Unterschied sichtbar.
             */
            Log::warning('Mailbox: Flags der sichtbaren Zeilen nicht geholt — alles erscheint gelesen. '.$fehler->getMessage());
        }

        return $flaggen;
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
                self::fuerListenzeilen($ordner->messages()->newest())->limit(MessagePage::FETCH_LIMIT)->get()
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
    /**
     * Die Felder, die eine vollstaendige LISTENZEILE braucht.
     *
     * An einer Stelle, weil das Vergessen eines Feldes hier nicht auffaellt:
     * Die Bibliothek antwortet dann nicht mit einem Fehler, sondern mit einem
     * plausiblen Wert. `withBodyStructure()` wegzulassen kostete nicht die
     * Anhangzahl — es machte sie zu `0`, in jeder Liste, fuer immer (Bug
     * #1025). Wer einen neuen Listenweg baut, nimmt diese Methode und erbt
     * damit die Felder, statt sie neu aufzuzaehlen.
     *
     * Kostet rund ein Viertel mehr Zeit als ohne die Struktur (gemessen am
     * 15.10.2026: 265 ms statt 208 ms fuer 100 Nachrichten gegen einen Server
     * im selben Netz). Das ist Datenmenge im selben FETCH, kein zusaetzlicher
     * Gang zum Server — und der Preis dafuer, dass eine Mail mit Rechnung als
     * solche zu erkennen ist.
     */
    private static function fuerListenzeilen(mixed $abfrage): mixed
    {
        return $abfrage->withHeaders()->withFlags()->withBodyStructure();
    }

    /**
     * Die Felder der billigen Dreiergruppe — bewusst OHNE Struktur.
     *
     * `newest()`, `newerThan()` und `olderThan()` liefern keine Anhangzahl und
     * keine Vorschau; sie sind das sparsame Gegenstueck fuer ein Verzeichnis,
     * das nur buendeln will. Eigene Methode statt derselben Kette, damit der
     * Unterschied zu {@see fuerListenzeilen()} eine Entscheidung ist und nicht
     * wie ein vergessenes Feld aussieht.
     */
    private static function fuerKopfzeilen(mixed $abfrage): mixed
    {
        return $abfrage->withHeaders()->withFlags();
    }

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
                // Dieselbe Reihenfolge wie in der Listenzeile: Der
                // Vertragstest vergleicht die Schluessel streng, damit eine
                // neue Spalte auf beiden Wegen an derselben Stelle auftaucht.
                'from_address' => $von?->email() ?? '',
                'from_name' => $von?->name() ?? '',
                'date' => $nachricht->date()?->toIso8601String(),
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
     * The newest header rows of a folder, without bodies.
     *
     * @return list<array<string, mixed>>
     */
    public function newest(string $folder, int $limit = 200): array
    {
        return $this->cheapRowsFrom($folder, fn ($abfrage) => $abfrage->newest(), $limit);
    }

    /**
     * Just the handles of everything in a folder.
     *
     * Ohne `withHeaders`/`withFlags`/`withBody` ueberspringt die Bibliothek
     * den FETCH vollstaendig und liefert allein die Nummern aus
     * `UID SEARCH ALL`. Ein Befehl je Ordner, keine Inhalte.
     *
     * @return list<int|string>
     */
    public function handles(string $folder): array
    {
        return $this->session(function ($mailbox) use ($folder): array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $ordner) {
                return [];
            }

            $kennungen = [];

            foreach ($ordner->messages()->withoutHeaders()->withoutFlags()->withoutBody()->get() as $nachricht) {
                $kennungen[] = $nachricht->uid();
            }

            return $kennungen;
        });
    }

    /**
     * Der Zustand eines Ordners: Gueltigkeitsnummer, naechste Kennung, Anzahl.
     *
     * `STATUS` statt `SELECT`: Es beantwortet dieselbe Frage, ohne den Ordner
     * zu oeffnen — und ohne dabei `\Recent`-Kennzeichen zu loeschen, was auf
     * manchen Servern als „jemand hat hineingesehen" zaehlt. Ein Lauf, der nur
     * nachzaehlt, soll im Postfach keine Spuren hinterlassen.
     */
    public function folderState(string $folder): ?array
    {
        return $this->session(function ($mailbox) use ($folder): ?array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $ordner) {
                return null;
            }

            $werte = $ordner->status();

            $zahl = static fn (string $name): ?int => isset($werte[$name]) ? (int) $werte[$name] : null;

            return [
                'uidvalidity' => $zahl('UIDVALIDITY'),
                'uidnext' => $zahl('UIDNEXT'),
                'messages' => $zahl('MESSAGES'),
            ];
        });
    }

    /**
     * Header rows of messages that arrived after this one.
     *
     * Ueber IMAP ist das die uid-Ordnung: Eine hoehere uid heisst, der Server
     * hat die Nachricht spaeter gesehen. Das ist NICHT dasselbe wie ein
     * spaeteres Datum — eine alte Mail, die gestern ankam, bekommt trotzdem
     * die hoehere uid. Genau das will ein Index aber: alles, was seit dem
     * letzten Lauf dazugekommen ist.
     *
     * @return list<array<string, mixed>>
     */
    public function newerThan(string $folder, int|string $handle, int $limit = 200): array
    {
        return $this->cheapRowsFrom($folder, fn ($abfrage) => $abfrage->uid((int) $handle + 1, INF), $limit);
    }

    /**
     * Header rows of messages that arrived before this one.
     *
     * @return list<array<string, mixed>>
     */
    public function olderThan(string $folder, int|string $handle, int $limit = 200): array
    {
        $bis = (int) $handle - 1;

        if ($bis < 1) {
            return [];
        }

        return $this->cheapRowsFrom($folder, fn ($abfrage) => $abfrage->uid(1, $bis)->newest(), $limit);
    }

    /**
     * Kopfzeilen-Zeilen aus einer eingegrenzten Abfrage.
     *
     * @param  callable(mixed): mixed  $eingrenzen
     * @return list<array<string, mixed>>
     */
    private function cheapRowsFrom(string $folder, callable $eingrenzen, int $limit): array
    {
        return $this->session(function ($mailbox) use ($folder, $eingrenzen, $limit): array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());

            if (! $ordner) {
                return [];
            }

            $abfrage = $eingrenzen(self::fuerKopfzeilen($ordner->messages()));

            $zeilen = [];

            foreach ($this->cheapRows($abfrage->limit($limit)->get()) as $zeile) {
                // Das Nachrichten-Objekt bleibt drinnen: Diese Zeilen gehen an
                // ein Produkt, nicht in eine Kette.
                unset($zeile['message']);
                $zeilen[] = $zeile;
            }

            return $zeilen;
        });
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

        foreach (self::fuerListenzeilen($abfrage)->limit($limit)->get() as $nachricht) {
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
            $pfade = [];

            foreach ($mailbox->folders()->get() as $ordner) {
                foreach ((array) ($ordner->flags() ?? []) as $flag) {
                    if (mb_strtolower((string) $flag) === mb_strtolower($kennzeichen)) {
                        return $ordner->path();
                    }
                }

                // Gesammelt, nicht genommen: Ein markierter Ordner weiter unten
                // in der Liste ist die bessere Antwort als ein passender Name.
                $pfade[] = (string) $ordner->path();
            }

            // Und unter den Namenstreffern entscheidet nicht die Reihenfolge
            // des Servers, sondern der kuerzeste Pfad — sonst schlaegt ein
            // Jahres-Unterordner seinen Elternordner.
            return FolderNames::bestMatch($pfade, $kind);
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
     * One message as it was sent — for keeping, not for showing.
     */
    public function verbatim(string $folder, int|string $uid): ?array
    {
        return $this->session(function ($mailbox) use ($folder, $uid): ?array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());
            $nachricht = $ordner?->messages()->withHeaders()->withFlags()->withBody()->find((int) $uid);

            return $nachricht ? $this->formatter->verbatim($nachricht) : null;
        });
    }

    /**
     * Die Nachricht als Bytes.
     *
     * IMAP gibt die Quelle in zwei Stuecken heraus: `BODY[HEADER]` und
     * `BODY[TEXT]`. Der Kopfteil endet laut RFC 3501 mit der Leerzeile, die
     * Kopf und Rumpf trennt — aneinandergehaengt ergeben beide wieder die
     * Nachricht.
     *
     * „Ergeben" ist hier eine Behauptung, und deshalb wird sie geprueft:
     * `RFC822.SIZE` sagt, wie gross die Nachricht auf dem Server ist. Stimmt
     * die Laenge nicht, ist `complete` falsch — und wer archiviert, weiss es,
     * statt eine unvollstaendige Kopie fuer den Beweis zu halten.
     */
    public function raw(string $folder, int|string $uid): ?array
    {
        return $this->session(function ($mailbox) use ($folder, $uid): ?array {
            $ordner = FolderResolver::resolve($mailbox->folders()->get(), $folder, fn ($f) => $f->path(), fn ($f) => $f->name());
            // `withSize()` gehoert dazu und ist kein Beiwerk: Ohne sie liefert
            // die Bibliothek `RFC822.SIZE` gar nicht erst, und die Pruefung
            // unten kann nichts vergleichen. Beim ersten Livelauf am
            // 23.09.2026 meldete daraufhin JEDE Nachricht „unbestaetigt" —
            // die Pruefung war richtig, die Abfrage unvollstaendig.
            $nachricht = $ordner?->messages()->withHeaders()->withBody()->withSize()->find((int) $uid);

            if (! $nachricht) {
                return null;
            }

            return RohFassung::ausTeilen(
                $nachricht->head(),
                $nachricht->body(),
                $nachricht->size(),
            )->toArray();
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
     * Deletes a message for good.
     */
    public function purge(string $folder, int|string $uid): bool
    {
        return $this->onMessage($folder, $uid, function ($message): bool {
            $message->delete(true);

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
    public function delete(string $folder, int|string $uid, array $trashNames = FolderNames::TRASH_ALIASES): bool
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

            /*
             * Die MARKE zuerst, der Name danach.
             *
             * Hier stand bis zum 20.10.2026 nur die Namenssuche — entgegen
             * dem, was der Vertrag dieser Methode zusagt („a transport that
             * knows the folder's role uses that"), und entgegen dem, was der
             * JMAP-Weg daneben schon tat (`role === 'trash'`).
             *
             * Der Unterschied ist nicht akademisch, denn der Rueckfall dieser
             * Methode ist das ENDGUELTIGE Loeschen. Ein Postfach, dessen
             * Papierkorb wir nicht benennen konnten, ist von einem ohne
             * Papierkorb nicht zu unterscheiden — und die Mail ist weg. Ein
             * `\Trash` liefert die Antwort ohne Raten; die Namen fangen die
             * Server auf, die keine Marken setzen.
             *
             * Zwei Durchgaenge und nicht einer: Ein markierter Ordner weiter
             * unten in der Liste ist die bessere Antwort als ein passender
             * Name weiter oben. Dieselbe Begruendung wie in
             * {@see specialFolder()}.
             */
            $ordnerliste = $mailbox->folders()->get();
            $papierkorb = null;

            foreach ($ordnerliste as $kandidat) {
                foreach ((array) ($kandidat->flags() ?? []) as $flag) {
                    if (mb_strtolower((string) $flag) === '\\trash') {
                        $papierkorb = $kandidat->path();
                        break 2;
                    }
                }
            }

            if ($papierkorb === null) {
                foreach ($ordnerliste as $kandidat) {
                    if (FolderPaths::matchesAny($kandidat->name(), $kandidat->path(), $trashNames)) {
                        $papierkorb = $kandidat->path();
                        break;
                    }
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
