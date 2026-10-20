<?php

use DirectoryTree\ImapEngine\BodyStructureCollection;
use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\ContentDisposition;
use DirectoryTree\ImapEngine\Enums\ContentDispositionType;
use DirectoryTree\ImapEngine\Message;
use Illuminate\Support\Carbon;
use Peppermint\Mailbox\Imap\MailboxClient;
use Peppermint\Mailbox\Imap\RetryPolicy;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Was eine Listenseite beim Server abfragt (#6119).
 *
 * ## Warum dieser Test das Verhalten und nicht das Ergebnis prüft
 *
 * Die Seite kam vorher richtig heraus — sie dauerte nur elf Sekunden. Kein
 * Test konnte das sehen, weil keiner hinsah, WAS abgefragt wird.
 *
 * Gemessen am 20.10.2026 an `department@peppermint-digital.de`, 49 Nachrichten
 * im Posteingang:
 *
 *     100 Zeilen mit BODYSTRUCTURE   3.100 ms
 *     100 Zeilen nur Kopf+Flags        930 ms
 *     25 Zeilen mit BODYSTRUCTURE      635 ms
 *
 * Die Struktur wurde für das ganze Hundert-Zeilen-Fenster geholt, obwohl nur
 * die sichtbaren fünfundzwanzig sie brauchen (für `attachment_count`). Das
 * Fenster selbst dient dem Sortieren nach Datum — dafür genügen Kopfzeilen.
 *
 * Diese Tests halten beide Hälften fest: dass das Fenster billig bleibt, und
 * dass die Struktur in EINEM Abruf nachgeholt wird und nicht je Zeile.
 */
function seitenNachricht(int $uid, string $betreff, string $datum, int $anhaenge = 0): Message
{
    $message = Mockery::mock(Message::class);

    $message->shouldReceive('uid')->andReturn($uid);
    $message->shouldReceive('messageId')->andReturn("<{$uid}@beispiel.de>");
    $message->shouldReceive('subject')->andReturn($betreff);
    $message->shouldReceive('from')->andReturn(null);
    $message->shouldReceive('date')->andReturn(Carbon::parse($datum));
    $message->shouldReceive('isSeen')->andReturn(true);
    $message->shouldReceive('isFlagged')->andReturn(false);
    $message->shouldReceive('header')->andReturn(null);
    $message->shouldReceive('text')->andThrow(new RuntimeException('Die Liste darf den Rumpf nicht anfassen'));
    $message->shouldReceive('html')->andThrow(new RuntimeException('Die Liste darf den Rumpf nicht anfassen'));

    /*
     * Die Struktur steckt nur in den Nachrichten des ZWEITEN Abrufs. Die
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
 * Ein Ordner, der jede Abfrage mitschreibt: welche Felder, welches Limit,
 * welche Kennungen.
 */
function seitenOrdner(array $fenster, array $detail = [], bool $detailScheitert = false): object
{
    return new class($fenster, $detail, $detailScheitert)
    {
        /** @var list<array<string, mixed>> */
        public array $abfragen = [];

        public function __construct(
            private array $fenster,
            private array $detail,
            private bool $detailScheitert,
        ) {}

        public function path(): string
        {
            return 'INBOX';
        }

        public function name(): string
        {
            return 'INBOX';
        }

        public function flags(): array
        {
            return [];
        }

        public function messages(): object
        {
            return new class($this)
            {
                private array $protokoll = ['struktur' => false, 'kennungen' => null, 'limit' => null];

                public function __construct(private $ordner) {}

                public function newest(): static
                {
                    return $this;
                }

                public function withHeaders(): static
                {
                    return $this;
                }

                public function withFlags(): static
                {
                    return $this;
                }

                public function withBodyStructure(): static
                {
                    $this->protokoll['struktur'] = true;

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
            // Der zweite Abruf: nach Kennungen gefiltert, mit Struktur.
            if ($protokoll['kennungen'] !== null) {
                if ($this->detailScheitert) {
                    throw new RuntimeException('Server mag keine UID-Liste');
                }

                return array_values(array_filter(
                    $this->detail,
                    fn (Message $m): bool => in_array($m->uid(), $protokoll['kennungen'], true),
                ));
            }

            return $this->fenster;
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

function seitenKlient(object $ordner): MailboxClient
{
    return new MailboxClient(
        account: MailAccount::fromRemote([
            'id' => 1, 'email' => 'post@beispiel.de', 'username' => null, 'password' => 'geheim',
            'host' => 'imap.beispiel.de', 'port' => 993, 'encryption' => 'ssl', 'auth_type' => 'password',
            'oauth_access_token' => null, 'oauth_token_expires_at' => null,
        ]),
        refresher: null,
        retry: new RetryPolicy(maxRetries: 1, sleeper: fn () => null, jitter: fn () => 0.0),
        connector: fn () => seitenPostfach($ordner),
    );
}

it('holt das Fenster OHNE Struktur und die Struktur nur fuer die sichtbaren Zeilen', function () {
    $fenster = [];
    $detail = [];

    // 30 Nachrichten, damit die Seite von 25 wirklich schneidet.
    for ($i = 30; $i >= 1; $i--) {
        $datum = sprintf('2026-09-%02dT10:00:00+00:00', $i);
        $fenster[] = seitenNachricht($i, "Mail {$i}", $datum);
        $detail[] = seitenNachricht($i, "Mail {$i}", $datum, $i <= 3 ? 2 : 0);
    }

    $ordner = seitenOrdner($fenster, $detail);

    [$zeilen, $gesamt] = seitenKlient($ordner)->page('INBOX', 1, 25);

    expect($zeilen)->toHaveCount(25)
        ->and($gesamt)->toBe(30);

    // ZWEI Abfragen, nicht sechsundzwanzig.
    expect($ordner->abfragen)->toHaveCount(2);

    [$erste, $zweite] = $ordner->abfragen;

    // Das Fenster: hundert Zeilen, OHNE Struktur.
    expect($erste['struktur'])->toBeFalse()
        ->and($erste['limit'])->toBe(100)
        ->and($erste['kennungen'])->toBeNull();

    // Der Nachschlag: MIT Struktur, und genau die 25 sichtbaren Kennungen.
    expect($zweite['struktur'])->toBeTrue()
        ->and($zweite['kennungen'])->toHaveCount(25);
});

it('nimmt die Anhangzahl aus dem zweiten Abruf — sonst waere sie still 0', function () {
    /*
     * Der Grund, warum die Struktur ueberhaupt geholt wird: Ohne sie gibt die
     * Bibliothek 0 heraus, und `0` sieht aus wie „keine Anhaenge" statt wie
     * „nicht gefragt" (Bug #1025).
     *
     * Die Fenster-Nachrichten dieses Tests liefern bewusst `bodyStructure()
     * === null` — wie ein Server, der ohne BODYSTRUCTURE gefragt wurde. Kommt
     * die Zahl trotzdem an, kann sie nur aus dem zweiten Abruf stammen.
     */
    $fenster = [seitenNachricht(1, 'Mit Anhang', '2026-09-01T10:00:00+00:00')];
    $detail = [seitenNachricht(1, 'Mit Anhang', '2026-09-01T10:00:00+00:00', 2)];

    [$zeilen] = seitenKlient(seitenOrdner($fenster, $detail))->page('INBOX', 1, 25);

    expect($zeilen[0]['attachment_count'])->toBe(2)
        ->and($zeilen[0]['has_attachments'])->toBeTrue();
});

it('liefert die Seite auch dann, wenn der zweite Abruf scheitert', function () {
    /*
     * Ein Server, der keine UID-Liste mag, darf nicht die ganze Seite kosten.
     * Dann wird mit den Fenster-Zeilen formatiert: Die Anhangzahl ist 0, und
     * `anhangZahl()` schreibt die Warnung aus Bug #1025 ins Log.
     *
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

it('fragt gar nicht nach, wenn die Seite leer ist', function () {
    // Eine UID-Liste ohne Kennungen waere ein Abruf fuer nichts — und bei
    // manchen Servern ein Fehler.
    $ordner = seitenOrdner([]);

    [$zeilen] = seitenKlient($ordner)->page('INBOX', 1, 25);

    expect($zeilen)->toBe([])
        ->and($ordner->abfragen)->toHaveCount(1);
});
