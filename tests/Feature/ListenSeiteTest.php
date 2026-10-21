<?php

use DirectoryTree\ImapEngine\BodyStructureCollection;
use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\ContentDisposition;
use DirectoryTree\ImapEngine\Enums\ContentDispositionType;
use DirectoryTree\ImapEngine\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Peppermint\Mailbox\Imap\MailboxClient;
use Peppermint\Mailbox\Imap\RetryPolicy;
use Peppermint\Mailbox\Models\MailAccount;

uses(RefreshDatabase::class);

/**
 * Was eine Listenseite beim Server abfragt (#6119).
 *
 * ## Warum dieser Test das Verhalten und nicht das Ergebnis prüft
 *
 * Die Seite kam vorher richtig heraus — sie dauerte nur elf Sekunden. Kein
 * Test konnte das sehen, weil keiner hinsah, WAS abgefragt wird.
 *
 * Gemessen am 20.10.2026 an `department@peppermint-digital.de`, 54 Nachrichten
 * im Posteingang, alles in EINER offenen Verbindung:
 *
 *     nur UIDs (SEARCH)                      69 ms
 *     + FLAGS                               123 ms
 *     + GANZER Kopfblock (490 KB)       660–1031 ms
 *     + nur 5 Kopffelder (12 KB)            688 ms
 *     + BODYSTRUCTURE                 2234–13024 ms
 *
 * Die dritte und vierte Zeile sind der Grund für das Kopfzeilen-Verzeichnis:
 * Zwölf Kilobyte kosten so viel wie vierhundertneunzig. Der Engpass ist die
 * Arbeit, die Exchange JE NACHRICHT leistet — rund 12,7 ms, unabhängig davon,
 * wie wenig man abfragt. Eine Feldauswahl ließe sich bauen und brächte nichts.
 *
 * ## Die Invariante, und was sie hier zu prüfen gibt
 *
 * > Das Verzeichnis beantwortet „was steht in UID 46807", NIE „welche Mails
 * > liegen in diesem Ordner".
 *
 * Deshalb stehen hier drei Proben, die ein Zwischenspeicher sonst still
 * verliert: Eine gelöschte Mail darf nicht erscheinen, eine angekommene nicht
 * fehlen, und eine anderswo gelesene Mail muss gelesen aussehen.
 */
function seitenNachricht(int $uid, string $betreff, string $datum, int $anhaenge = 0, bool $gelesen = true): Message
{
    $message = Mockery::mock(Message::class);

    $message->shouldReceive('uid')->andReturn($uid);
    $message->shouldReceive('messageId')->andReturn("<{$uid}@beispiel.de>");
    $message->shouldReceive('subject')->andReturn($betreff);
    $message->shouldReceive('from')->andReturn(null);
    $message->shouldReceive('date')->andReturn(Carbon::parse($datum));
    $message->shouldReceive('isSeen')->andReturn($gelesen);
    $message->shouldReceive('isFlagged')->andReturn(false);
    $message->shouldReceive('header')->andReturn(null);
    $message->shouldReceive('text')->andThrow(new RuntimeException('Die Liste darf den Rumpf nicht anfassen'));
    $message->shouldReceive('html')->andThrow(new RuntimeException('Die Liste darf den Rumpf nicht anfassen'));

    /*
     * Die Struktur steckt nur in den Nachrichten des STRUKTUR-Abrufs. Die
     * Fenster-Nachrichten liefern `null` — genau wie ein Server, der ohne
     * BODYSTRUCTURE gefragt wurde.
     */
    if ($anhaenge > 0) {
        $teile = [new BodyStructurePart(partNumber: '1', type: 'text', subtype: 'plain', parameters: [], id: null, disposition: null)];

        for ($i = 0; $i < $anhaenge; $i++) {
            $teile[] = new BodyStructurePart(
                partNumber: (string) ($i + 2),
                type: 'application',
                subtype: 'pdf',
                parameters: [],
                id: null,
                disposition: new ContentDisposition(ContentDispositionType::Attachment, ['filename' => "datei{$i}.pdf"]),
            );
        }

        $message->shouldReceive('bodyStructure')->andReturn(new BodyStructureCollection(parts: $teile));
    } else {
        $message->shouldReceive('bodyStructure')->andReturn(null);
        $message->shouldReceive('attachmentCount')->andReturn(0);
    }

    return $message;
}

/**
 * Ein Ordner, der jede Abfrage mitschreibt: welche Posten, welche Kennungen,
 * welches Limit.
 */
function seitenOrdner(array $fenster, array $detail = [], bool $detailScheitert = false, bool $ohneStatus = false, string $pfad = 'INBOX'): object
{
    return new class($fenster, $detail, $detailScheitert, $ohneStatus, $pfad)
    {
        /** @var list<array<string, mixed>> */
        public array $abfragen = [];

        public function __construct(
            private array $fenster,
            private array $detail,
            private bool $detailScheitert,
            private bool $ohneStatus,
            private string $pfad,
        ) {}

        public function path(): string
        {
            return $this->pfad;
        }

        public function name(): string
        {
            return $this->pfad;
        }

        public function flags(): array
        {
            return [];
        }

        public function status(): array
        {
            if ($this->ohneStatus) {
                throw new RuntimeException('STATUS not supported');
            }

            return [
                'MESSAGES' => (string) count($this->fenster),
                'UIDVALIDITY' => '14',
                'UIDNEXT' => (string) (count($this->fenster) + 1),
            ];
        }

        public function messages(): object
        {
            return new class($this)
            {
                private array $protokoll = ['posten' => [], 'kennungen' => null, 'limit' => null];

                public function __construct(private $ordner) {}

                public function newest(): static
                {
                    return $this;
                }

                public function withHeaders(): static
                {
                    $this->protokoll['posten'][] = 'kopf';

                    return $this;
                }

                public function withFlags(): static
                {
                    $this->protokoll['posten'][] = 'flags';

                    return $this;
                }

                public function withBodyStructure(): static
                {
                    $this->protokoll['posten'][] = 'struktur';

                    return $this;
                }

                public function withoutHeaders(): static
                {
                    return $this;
                }

                public function withoutFlags(): static
                {
                    return $this;
                }

                public function withoutBody(): static
                {
                    return $this;
                }

                public function uid(int|string|array $von, int|float|null $bis = null): static
                {
                    $this->protokoll['kennungen'] = is_array($von) ? $von : [$von, $bis];

                    return $this;
                }

                public function limit(int $n): static
                {
                    $this->protokoll['limit'] = $n;

                    return $this;
                }

                public function count(): int
                {
                    return $this->ordner->anzahl();
                }

                public function get(): array
                {
                    $this->ordner->abfragen[] = $this->protokoll;

                    return $this->ordner->antwort($this->protokoll);
                }
            };
        }

        public function anzahl(): int
        {
            return count($this->fenster);
        }

        public function antwort(array $protokoll): array
        {
            $aus = in_array('struktur', $protokoll['posten'], true) ? $this->detail : $this->fenster;

            if ($protokoll['kennungen'] === null) {
                return $aus;
            }

            if ($this->detailScheitert && in_array('struktur', $protokoll['posten'], true)) {
                throw new RuntimeException('Server mag keine UID-Liste');
            }

            $gesucht = array_map('strval', $protokoll['kennungen']);

            return array_values(array_filter(
                $aus,
                static fn (Message $m): bool => in_array((string) $m->uid(), $gesucht, true),
            ));
        }
    };
}

function seitenPostfach(object $ordner): object
{
    return new class($ordner)
    {
        public function __construct(private object $ordner) {}

        public function folders(): object
        {
            return new class($this->ordner)
            {
                public function __construct(private object $ordner) {}

                public function get(): array
                {
                    return [$this->ordner];
                }
            };
        }

        public function connect(): void {}

        public function disconnect(): void {}
    };
}

function seitenKlient(object $ordner, int $konto = 1): MailboxClient
{
    return new MailboxClient(
        account: MailAccount::fromRemote([
            'id' => $konto, 'email' => 'post@beispiel.de', 'username' => null, 'password' => 'geheim',
            'host' => 'imap.beispiel.de', 'port' => 993, 'encryption' => 'ssl', 'auth_type' => 'password',
            'oauth_access_token' => null, 'oauth_token_expires_at' => null,
        ]),
        refresher: null,
        retry: new RetryPolicy(maxRetries: 1, sleeper: fn () => null, jitter: fn () => 0.0),
        connector: fn () => seitenPostfach($ordner),
    );
}

/** Dreissig Nachrichten, damit eine Seite von 25 wirklich schneidet. */
function seitenBestand(int $wieviele = 30, int $mitAnhang = 3): array
{
    $fenster = [];
    $detail = [];

    for ($i = $wieviele; $i >= 1; $i--) {
        $datum = sprintf('2026-09-%02dT10:00:00+00:00', $i);
        $fenster[] = seitenNachricht($i, "Mail {$i}", $datum);
        $detail[] = seitenNachricht($i, "Mail {$i}", $datum, $i <= $mitAnhang ? 2 : 0);
    }

    return [$fenster, $detail];
}

/** @return list<array<string, mixed>> */
function abfragenMit(object $ordner, string $posten): array
{
    return array_values(array_filter(
        $ordner->abfragen,
        static fn (array $a): bool => in_array($posten, $a['posten'], true),
    ));
}

it('holt beim ersten Mal die Kopfzeilen des Fensters und die Struktur nur fuer die Sichtbaren', function () {
    [$fenster, $detail] = seitenBestand();

    $ordner = seitenOrdner($fenster, $detail);

    [$zeilen, $gesamt] = seitenKlient($ordner)->page('INBOX', 1, 25);

    expect($zeilen)->toHaveCount(25)
        ->and($gesamt)->toBe(30);

    // Vier Abrufe: Belegung, Kopfzeilen, Struktur, Flags. Nicht einunddreissig.
    expect($ordner->abfragen)->toHaveCount(4);

    // Erstens: die BELEGUNG, ohne jeden Posten. Die darf nichts verschweigen.
    expect($ordner->abfragen[0]['posten'])->toBe([])
        ->and($ordner->abfragen[0]['limit'])->toBe(100)
        ->and($ordner->abfragen[0]['kennungen'])->toBeNull();

    // Zweitens: Kopfzeilen fuer die unbekannten Kennungen — alle dreissig.
    $kopf = abfragenMit($ordner, 'kopf');
    expect($kopf)->toHaveCount(1)
        ->and($kopf[0]['kennungen'])->toHaveCount(30);

    // Drittens: die STRUKTUR nur fuer die fuenfundzwanzig sichtbaren.
    $struktur = abfragenMit($ordner, 'struktur');
    expect($struktur)->toHaveCount(1)
        ->and($struktur[0]['kennungen'])->toHaveCount(25);
});

it('fragt beim zweiten Mal weder Kopfzeilen noch Struktur — nur noch Belegung und Flags', function () {
    /*
     * Das ist der ganze Zweck des Verzeichnisses. Gemessen an einem echten
     * Postfach: 2.045 ms auf 520–605 ms. Dabei kam ueber 25 Zeilen und 13
     * Felder KEIN einziges abweichendes Feld heraus — kalt gegen warm.
     */
    // Alle mit Anhang, damit die sichtbare Seite welche enthaelt: Sortiert
    // wird nach Datum, und die drei aeltesten liegen gar nicht auf Seite eins.
    [$fenster, $detail] = seitenBestand(30, 30);

    seitenKlient(seitenOrdner($fenster, $detail))->page('INBOX', 1, 25);

    $zweiter = seitenOrdner($fenster, $detail);
    [$zeilen] = seitenKlient($zweiter)->page('INBOX', 1, 25);

    expect($zeilen)->toHaveCount(25)
        ->and(abfragenMit($zweiter, 'kopf'))->toBe([])
        ->and(abfragenMit($zweiter, 'struktur'))->toBe([]);

    // Zwei Abrufe: die Belegung und die Flags.
    expect($zweiter->abfragen)->toHaveCount(2)
        ->and($zweiter->abfragen[1]['posten'])->toBe(['flags'])
        ->and($zweiter->abfragen[1]['kennungen'])->toHaveCount(25);

    // Und die teuer erkaufte Anhangzahl ist noch da.
    expect($zeilen[0]['attachment_count'])->toBe(2);
});

it('laesst bei gleichem Datum die Reihenfolge des SERVERS entscheiden', function () {
    /*
     * Was die Schleife ueber die Server-Kennungen wirklich leistet. Die
     * Invariante haengt am `whereIn` des Verzeichnisses — eine Mutationsprobe
     * hat gezeigt, dass die Schleife daran keinen Anteil hat.
     *
     * Ihre Aufgabe ist die REIHENFOLGE: PHP sortiert seit 8.0 stabil, also
     * entscheidet bei gleichem Datum die Eingabe. Kaeme die aus der Datenbank,
     * stuende dort die Einfuegereihenfolge — und die ist nach einem Nachtrag
     * eine andere als die des Servers. Dieselbe Liste saehe dann je nach
     * Vorgeschichte anders aus.
     */
    $gleich = '2026-09-10T10:00:00+00:00';

    $erst = [seitenNachricht(5, 'fuenf', $gleich)];
    seitenKlient(seitenOrdner($erst, $erst))->page('INBOX', 1, 25);

    // Die neue Kennung kommt beim Server ZUERST, in der Tabelle als LETZTE.
    $dann = [seitenNachricht(9, 'neun', $gleich), seitenNachricht(5, 'fuenf', $gleich)];

    [$zeilen] = seitenKlient(seitenOrdner($dann, $dann))->page('INBOX', 1, 25);

    expect(array_column($zeilen, 'uid'))->toBe([9, 5])
        ->and(DB::table('mailbox_headers')->orderBy('id')->pluck('uid')->all())->toBe(['5', '9']);
});

it('zeigt eine geloeschte Mail nicht mehr, obwohl sie verzeichnet ist', function () {
    /*
     * Die Invariante. Ein Verzeichnis, das die BELEGUNG beantwortet, haette
     * hier eine Nachricht gezeigt, die es nicht mehr gibt — und das waere kein
     * Absturz, sondern eine Liste, die falsch aussieht wie eine richtige.
     */
    [$fenster, $detail] = seitenBestand(5, 5);

    seitenKlient(seitenOrdner($fenster, $detail))->page('INBOX', 1, 25);

    expect(DB::table('mailbox_headers')->count())->toBe(5);

    // Zwei sind weg — der Server nennt sie nicht mehr.
    $uebrig = array_slice($fenster, 0, 3);
    $uebrigDetail = array_slice($detail, 0, 3);

    [$zeilen, $gesamt] = seitenKlient(seitenOrdner($uebrig, $uebrigDetail))->page('INBOX', 1, 25);

    expect($zeilen)->toHaveCount(3)->and($gesamt)->toBe(3);
});

it('holt die Kopfzeilen nur fuer die NEUEN Kennungen, nicht fuer alle', function () {
    [$fenster, $detail] = seitenBestand(5, 0);

    seitenKlient(seitenOrdner($fenster, $detail))->page('INBOX', 1, 25);

    // Zwei kommen dazu.
    $mehr = array_merge([
        seitenNachricht(6, 'ganz neu', '2026-09-20T10:00:00+00:00'),
        seitenNachricht(7, 'noch neuer', '2026-09-21T10:00:00+00:00'),
    ], $fenster);
    $mehrDetail = array_merge([
        seitenNachricht(6, 'ganz neu', '2026-09-20T10:00:00+00:00'),
        seitenNachricht(7, 'noch neuer', '2026-09-21T10:00:00+00:00'),
    ], $detail);

    $zweiter = seitenOrdner($mehr, $mehrDetail);
    [$zeilen] = seitenKlient($zweiter)->page('INBOX', 1, 25);

    expect($zeilen)->toHaveCount(7);

    $kopf = abfragenMit($zweiter, 'kopf');
    expect($kopf)->toHaveCount(1)
        ->and($kopf[0]['kennungen'])->toHaveCount(2)
        ->and(array_map('intval', $kopf[0]['kennungen']))->toEqualCanonicalizing([6, 7]);
});

it('raeumt verzeichnete Kennungen weg, die der Server nicht mehr nennt', function () {
    /*
     * Gelesen werden die Toten nie — die Belegung kommt immer vom Server.
     * Gemessen am 21.10.2026 an `department@` wuchsen sie aber fuer immer: 65
     * verzeichnete Zeilen fuer 30 vorhandene Nachrichten.
     */
    [$fenster, $detail] = seitenBestand(5, 0);

    seitenKlient(seitenOrdner($fenster, $detail))->page('INBOX', 1, 25);

    expect(DB::table('mailbox_headers')->count())->toBe(5);

    // Zwei sind weg (3 und 4), eine kam dazu (6).
    $nachher = [
        seitenNachricht(6, 'neu', '2026-09-20T10:00:00+00:00'),
        seitenNachricht(5, 'Mail 5', '2026-09-05T10:00:00+00:00'),
        seitenNachricht(2, 'Mail 2', '2026-09-02T10:00:00+00:00'),
        seitenNachricht(1, 'Mail 1', '2026-09-01T10:00:00+00:00'),
    ];

    [$zeilen] = seitenKlient(seitenOrdner($nachher, $nachher))->page('INBOX', 1, 25);

    expect($zeilen)->toHaveCount(4)
        ->and(DB::table('mailbox_headers')->orderBy('uid')->pluck('uid')->all())->toBe(['1', '2', '5', '6']);
});

it('raeumt NICHTS weg, was aelter ist als das Fenster', function () {
    /*
     * Der gefaehrliche Fall. Das Fenster umfasst die hundert jungsten
     * Kennungen; was darunter liegt, ist nicht tot, sondern nur ausserhalb.
     * Eine Regel „loesche, was nicht im Fenster steht" wuerde bei jedem Blick
     * in einen grossen Ordner das Verzeichnis aller aelteren Nachrichten
     * leeren — und damit genau den Gewinn vernichten, den es bringen soll.
     */
    DB::table('mailbox_headers')->insert([
        'account_id' => 1, 'folder' => 'INBOX', 'uid' => '7', 'uidvalidity' => 14,
        'subject' => 'lange her', 'date' => '2026-01-01T10:00:00+00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /*
     * Das Fenster muss VOLL sein, damit es ein Fenster ist: Hundert Kennungen
     * (100 bis 199), und die 7 liegt weit darunter. Waeren es weniger als
     * hundert, waere das Fenster die ganze Belegung — dann gibt es kein
     * „darunter", und die 7 waere zu Recht tot.
     */
    $fenster = [];
    $detail = [];

    for ($uid = 199; $uid >= 100; $uid--) {
        $datum = sprintf('2026-09-%02dT10:00:00+00:00', ($uid % 28) + 1);
        $fenster[] = seitenNachricht($uid, "Mail {$uid}", $datum);
        $detail[] = seitenNachricht($uid, "Mail {$uid}", $datum);
    }

    expect($fenster)->toHaveCount(100);

    seitenKlient(seitenOrdner($fenster, $detail))->page('INBOX', 1, 25);

    expect(DB::table('mailbox_headers')->where('uid', '7')->count())->toBe(1);
});

it('vergleicht die Kennungen als Zahl und nicht als Text', function () {
    /*
     * `'100' < '30'` ist als Text wahr und als Nummer falsch. Mit einem
     * Textvergleich waere die 30 „aelter als das Fenster" und bliebe als
     * Leiche stehen, obwohl der Server sie nicht mehr nennt.
     */
    $erst = [];
    $detail = [];

    foreach ([100, 30] as $uid) {
        $erst[] = seitenNachricht($uid, "Mail {$uid}", '2026-09-0'.($uid === 100 ? '2' : '1').'T10:00:00+00:00');
        $detail[] = seitenNachricht($uid, "Mail {$uid}", '2026-09-0'.($uid === 100 ? '2' : '1').'T10:00:00+00:00');
    }

    seitenKlient(seitenOrdner($erst, $detail))->page('INBOX', 1, 25);

    expect(DB::table('mailbox_headers')->orderBy('uid')->pluck('uid')->all())->toBe(['100', '30']);

    // Die 30 verschwindet, die 101 kommt.
    $dann = [
        seitenNachricht(101, 'neu', '2026-09-03T10:00:00+00:00'),
        seitenNachricht(100, 'Mail 100', '2026-09-02T10:00:00+00:00'),
    ];

    seitenKlient(seitenOrdner($dann, $dann))->page('INBOX', 1, 25);

    expect(DB::table('mailbox_headers')->orderBy('uid')->pluck('uid')->all())->toBe(['100', '101']);
});

it('raeumt eine Kennung, die keine Zahl ist, niemals weg', function () {
    /*
     * IMAP zaehlt, JMAP vergibt Zeichenketten wie `cqiaaaaus`. Dieses
     * Verzeichnis bedient heute nur IMAP, die Spalte ist aber breit — und dann
     * muss auch der Vergleich sie ertragen.
     *
     * Ohne den Cast auf `int` vergleicht PHP 8 sie als Text mit der
     * Untergrenze: `'cqiaaaaus' < '100'` ist falsch, die Zeile gaelte als tot
     * und waere weg. Mit dem Cast wird sie zu 0, liegt immer unter der Grenze
     * und bleibt stehen.
     */
    DB::table('mailbox_headers')->insert([
        'account_id' => 1, 'folder' => 'INBOX', 'uid' => 'cqiaaaaus', 'uidvalidity' => 14,
        'subject' => 'aus einem JMAP-Postfach', 'date' => '2026-01-01T10:00:00+00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Volles Fenster, damit die Untergrenze ueberhaupt gilt.
    $fenster = [];
    $detail = [];

    for ($uid = 199; $uid >= 100; $uid--) {
        $datum = sprintf('2026-09-%02dT10:00:00+00:00', ($uid % 28) + 1);
        $fenster[] = seitenNachricht($uid, "Mail {$uid}", $datum);
        $detail[] = seitenNachricht($uid, "Mail {$uid}", $datum);
    }

    seitenKlient(seitenOrdner($fenster, $detail))->page('INBOX', 1, 25);

    expect(DB::table('mailbox_headers')->where('uid', 'cqiaaaaus')->count())->toBe(1);
});

it('nimmt die Lesemarke IMMER vom Server, nie aus dem Verzeichnis', function () {
    /*
     * Flags aendert jeder andere Client jederzeit. Eine verzeichnete Lesemarke
     * waere die Art Fehler, die niemand als Fehler erkennt: Die Liste sieht
     * richtig aus und ist falsch.
     */
    $ungelesen = [seitenNachricht(1, 'Posteingang', '2026-09-01T10:00:00+00:00', 0, gelesen: false)];

    [$zeilen] = seitenKlient(seitenOrdner($ungelesen, $ungelesen))->page('INBOX', 1, 25);

    expect($zeilen[0]['is_read'])->toBeFalse();

    // Jemand anders liest sie — am Verzeichnis aendert das nichts.
    $gelesen = [seitenNachricht(1, 'Posteingang', '2026-09-01T10:00:00+00:00', 0, gelesen: true)];

    [$zeilen] = seitenKlient(seitenOrdner($gelesen, $gelesen))->page('INBOX', 1, 25);

    expect($zeilen[0]['is_read'])->toBeTrue();
});

it('verzeichnet die Anhangzahl erst, wenn eine Zeile wirklich gezeigt wurde', function () {
    /*
     * `0` heisst „keine Anhaenge", NULL heisst „noch nicht gefragt" — die
     * Unterscheidung, die Bug #1025 gekostet hat. Die Struktur ist der
     * teuerste Abruf ueberhaupt; sie wird nur fuer sichtbare Zeilen geholt.
     */
    [$fenster, $detail] = seitenBestand(30, 30);

    seitenKlient(seitenOrdner($fenster, $detail))->page('INBOX', 1, 25);

    expect(DB::table('mailbox_headers')->whereNotNull('attachment_count')->count())->toBe(25)
        ->and(DB::table('mailbox_headers')->whereNull('attachment_count')->count())->toBe(5);
});

it('liefert die Seite auch dann, wenn der Struktur-Abruf scheitert', function () {
    /*
     * Ein Server, der keine UID-Liste mag, darf nicht die ganze Seite kosten.
     * Eine Nachricht ohne Anhang-Klammer ist ein kleinerer Schaden als eine
     * Seite, die gar nicht kommt.
     */
    $fenster = [seitenNachricht(1, 'Mit Anhang', '2026-09-01T10:00:00+00:00')];
    $detail = [seitenNachricht(1, 'Mit Anhang', '2026-09-01T10:00:00+00:00', 2)];

    [$zeilen] = seitenKlient(seitenOrdner($fenster, $detail, detailScheitert: true))->page('INBOX', 1, 25);

    expect($zeilen)->toHaveCount(1)
        ->and($zeilen[0]['subject'])->toBe('Mit Anhang')
        ->and($zeilen[0]['attachment_count'])->toBe(0);
});

it('fragt gar nicht nach, wenn der Ordner leer ist', function () {
    // Eine UID-Liste ohne Kennungen waere ein Abruf fuer nichts — und bei
    // manchen Servern ein Fehler.
    $ordner = seitenOrdner([]);

    [$zeilen] = seitenKlient($ordner)->page('INBOX', 1, 25);

    expect($zeilen)->toBe([])
        ->and($ordner->abfragen)->toHaveCount(1);
});

it('laeuft ohne STATUS weiter — langsamer, nicht fehlend', function () {
    /*
     * Die Liste haengt jetzt an einem Befehl, an dem sie vorher nicht hing.
     * Ein Server, der ihn verweigert, soll eine langsamere Liste bekommen.
     * Ohne Gueltigkeit wird das Verzeichnis nicht gelesen — im Zweifel lieber
     * den Server fragen.
     */
    [$fenster, $detail] = seitenBestand(5, 5);

    $ordner = seitenOrdner($fenster, $detail, ohneStatus: true);
    [$zeilen, $gesamt] = seitenKlient($ordner)->page('INBOX', 1, 25);

    expect($zeilen)->toHaveCount(5)
        ->and($gesamt)->toBe(5)
        ->and(abfragenMit($ordner, 'kopf'))->toHaveCount(1);
});

it('verhaelt sich abgeschaltet wie beim ersten Mal — ein Codeweg, nicht zwei', function () {
    config()->set('mailbox.header_index', false);

    [$fenster, $detail] = seitenBestand(5, 5);

    seitenKlient(seitenOrdner($fenster, $detail))->page('INBOX', 1, 25);

    $zweiter = seitenOrdner($fenster, $detail);
    [$zeilen] = seitenKlient($zweiter)->page('INBOX', 1, 25);

    expect($zeilen)->toHaveCount(5)
        ->and(DB::table('mailbox_headers')->count())->toBe(0)
        // Kopfzeilen und Struktur kommen weiterhin jedes Mal vom Server.
        ->and(abfragenMit($zweiter, 'kopf'))->toHaveCount(1)
        ->and(abfragenMit($zweiter, 'struktur'))->toHaveCount(1);
});

it('trennt die Konten — ein Verzeichnis ist nicht gemeinsam', function () {
    [$fenster, $detail] = seitenBestand(5, 5);

    seitenKlient(seitenOrdner($fenster, $detail), konto: 1)->page('INBOX', 1, 25);

    $anderes = seitenOrdner($fenster, $detail);
    seitenKlient($anderes, konto: 2)->page('INBOX', 1, 25);

    // Dieselben Kennungen, anderes Konto: Es muss neu geholt werden.
    expect(abfragenMit($anderes, 'kopf'))->toHaveCount(1)
        ->and(DB::table('mailbox_headers')->distinct()->count('account_id'))->toBe(2);
});

it('vergisst das Verzeichnis, wenn die Gueltigkeit des Ordners wechselt', function () {
    [$fenster, $detail] = seitenBestand(5, 5);

    seitenKlient(seitenOrdner($fenster, $detail))->page('INBOX', 1, 25);

    // Aus dem Ordner ist ein anderer geworden: dieselben Kennungen, andere Mails.
    DB::table('mailbox_headers')->update(['uidvalidity' => 13]);

    $zweiter = seitenOrdner($fenster, $detail);
    seitenKlient($zweiter)->page('INBOX', 1, 25);

    expect(abfragenMit($zweiter, 'kopf'))->toHaveCount(1)
        ->and(DB::table('mailbox_headers')->where('uidvalidity', 13)->count())->toBe(0)
        ->and(DB::table('mailbox_headers')->where('uidvalidity', 14)->count())->toBe(5);
});

it('laeuft weiter, wenn die Tabelle fehlt — ein Release liegt vor der Migration', function () {
    /*
     * Ein Paket-Release liegt immer VOR der Migration der vier Produkte. In dem
     * Fenster dazwischen muss die Mailliste laufen, nicht abstuerzen.
     */
    DB::statement('DROP TABLE mailbox_headers');

    [$fenster, $detail] = seitenBestand(5, 5);

    $ordner = seitenOrdner($fenster, $detail);
    [$zeilen] = seitenKlient($ordner)->page('INBOX', 1, 25);

    expect($zeilen)->toHaveCount(5)
        ->and($zeilen[0]['attachment_count'])->toBe(2)
        ->and(abfragenMit($ordner, 'kopf'))->toHaveCount(1);
});
