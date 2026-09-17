<?php

use Peppermint\Mailbox\Imap\MailboxClient;
use Peppermint\Mailbox\Imap\MessageFormatter;
use Peppermint\Mailbox\Imap\RetryPolicy;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Search\Criteria;

/**
 * Die Suche — das erste der Verben, die dem Vertrag fehlten (#5844).
 *
 * Sie lag bisher nur im Manager (ImapService, 1763 Zeilen). CRM und Verwaltung
 * hatten deshalb nie eine. Hier steht sie einmal, fuer beide Transporte.
 */

/** Ein Formatierer, der jede Nachricht nimmt — geprueft wird die Suche, nicht die Formatierung. */
function loserFormatierer(): MessageFormatter
{
    return new class extends MessageFormatter
    {
        // Parametertyp darf in PHP erweitert werden; die Fakes sind keine
        // ImapEngine-Nachrichten und sollen es auch nicht sein.
        public function summary(mixed $message): array
        {
            return ['uid' => $message->uid, 'subject' => $message->subject, 'date' => $message->date];
        }
    };
}

/** Ein Ordner, dessen Nachrichten-Abfrage mitschreibt, wonach gesucht wurde. */
function suchOrdner(string $pfad, array $flags = [], array $nachrichten = [], bool $sperrt = false): object
{
    return new class($pfad, $flags, $nachrichten, $sperrt)
    {
        public array $gesucht = [];

        public function __construct(
            private string $pfad,
            private array $flags,
            private array $nachrichten,
            private bool $sperrt,
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
            return $this->flags;
        }

        public function messages(): object
        {
            if ($this->sperrt) {
                throw new RuntimeException("Ordner gesperrt: {$this->pfad}");
            }

            return new class($this->nachrichten, $this)
            {
                public function __construct(private array $nachrichten, private $ordner) {}

                public function newest(): static
                {
                    return $this;
                }

                public function text(string $w): static
                {
                    $this->ordner->gesucht['text'] = $w;

                    return $this;
                }

                public function from(string $w): static
                {
                    $this->ordner->gesucht['from'] = $w;

                    return $this;
                }

                public function subject(string $w): static
                {
                    $this->ordner->gesucht['subject'] = $w;

                    return $this;
                }

                public function since(DateTimeInterface $w): static
                {
                    $this->ordner->gesucht['since'] = $w->format('Y-m-d');

                    return $this;
                }

                public function unseen(): static
                {
                    $this->ordner->gesucht['unseen'] = true;

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

                public function limit(int $n): static
                {
                    $this->ordner->gesucht['limit'] = $n;
                    $this->nachrichten = array_slice($this->nachrichten, 0, $n);

                    return $this;
                }

                public function get(): array
                {
                    return $this->nachrichten;
                }
            };
        }
    };
}

function suchNachricht(int $uid, string $betreff, string $datum): object
{
    return new class($uid, $betreff, $datum)
    {
        public function __construct(public int $uid, public string $subject, public string $date) {}
    };
}

function suchPostfach(array $ordner): object
{
    return new class($ordner)
    {
        public function __construct(private array $ordner) {}

        public function folders(): object
        {
            return new class($this->ordner)
            {
                public function __construct(private array $ordner) {}

                public function get(): array
                {
                    return $this->ordner;
                }
            };
        }

        public function disconnect(): void {}
    };
}

function suchKlient(object $postfach): MailboxClient
{
    return new MailboxClient(
        account: MailAccount::fromRemote([
            'id' => 1, 'email' => 'post@example.test', 'password' => 'geheim',
            'imap_host' => 'imap.example.test', 'auth_type' => 'password',
            'oauth_access_token' => null, 'oauth_token_expires_at' => null,
        ]),
        retry: new RetryPolicy(maxRetries: 1, sleeper: fn () => null, jitter: fn () => 0.0),
        connector: fn () => $postfach,
        formatter: loserFormatierer(),
    );
}

describe('die Suchanfrage', function () {
    it('nimmt leere Angaben nicht als Suche', function () {
        expect(Criteria::fromArray([])->isEmpty())->toBeTrue()
            ->and(Criteria::fromArray(['text' => '   '])->isEmpty())->toBeTrue()
            ->and(Criteria::fromArray(['unseen' => true])->isEmpty())->toBeFalse();
    });

    it('liest das Datum genau einmal, statt es jedem Transport zu ueberlassen', function () {
        $c = Criteria::fromArray(['since' => '2026-09-01 08:30:00']);

        expect($c->since)->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('uebersetzt sich in einen JMAP-Filter', function () {
        $filter = Criteria::fromArray([
            'text' => 'Angebot', 'from' => 'kunde@example.test',
            'since' => '2026-09-01T00:00:00+02:00', 'unseen' => true,
        ])->toJmapFilter();

        expect($filter['text'])->toBe('Angebot')
            ->and($filter['from'])->toBe('kunde@example.test')
            // In UTC mit Z — ein lokaler Versatz wird von manchen Servern
            // stillschweigend ignoriert.
            ->and($filter['after'])->toBe('2026-08-31T22:00:00Z')
            // JMAP kennt kein NICHT: ungelesen ist die ABWESENHEIT von $seen.
            ->and($filter['notKeyword'])->toBe('$seen');
    });
});

describe('Suche in einem Ordner (IMAP)', function () {
    it('reicht alle Angaben an die Abfrage durch', function () {
        $ordner = suchOrdner('INBOX', ['\\Inbox'], [suchNachricht(1, 'Angebot', '2026-09-01T10:00:00+00:00')]);

        suchKlient(suchPostfach([$ordner]))->search('INBOX', Criteria::fromArray([
            'text' => 'Angebot', 'from' => 'kunde@x', 'subject' => 'A', 'since' => '2026-09-01', 'unseen' => true,
        ]), 25);

        expect($ordner->gesucht)->toMatchArray([
            'text' => 'Angebot', 'from' => 'kunde@x', 'subject' => 'A', 'since' => '2026-09-01', 'unseen' => true, 'limit' => 25,
        ]);
    });

    it('sortiert nach Datum, nicht nach uid', function () {
        // uid sagt, wann der Server die Mail sah — nicht, wann sie geschickt wurde.
        $ordner = suchOrdner('INBOX', [], [
            suchNachricht(9, 'alt', '2026-01-01T10:00:00+00:00'),
            suchNachricht(1, 'neu', '2026-09-01T10:00:00+00:00'),
        ]);

        [$zeilen, $gesamt] = suchKlient(suchPostfach([$ordner]))->search('INBOX', Criteria::fromArray(['text' => 'x']));

        expect($zeilen[0]['subject'])->toBe('neu')->and($gesamt)->toBe(2);
    });

    it('antwortet auf einen unbekannten Ordner leer', function () {
        [$zeilen, $gesamt] = suchKlient(suchPostfach([suchOrdner('INBOX')]))
            ->search('Gibt-Es-Nicht', Criteria::fromArray(['text' => 'x']));

        expect($zeilen)->toBe([])->and($gesamt)->toBe(0);
    });

    it('weist eine leere Suche ab, statt das ganze Postfach zu holen', function () {
        expect(fn () => suchKlient(suchPostfach([suchOrdner('INBOX')]))->search('INBOX', Criteria::fromArray([])))
            ->toThrow(InvalidArgumentException::class, 'every message');
    });
});

describe('Suche ueber alle Ordner (IMAP)', function () {
    $postfach = fn () => suchPostfach([
        suchOrdner('INBOX', ['\\Inbox'], [suchNachricht(1, 'Treffer Eingang', '2026-09-02T10:00:00+00:00')]),
        suchOrdner('Gel&APY-schte Elemente', ['\\Trash'], [suchNachricht(2, 'Weggeworfen', '2026-09-03T10:00:00+00:00')]),
        suchOrdner('Junk', ['\\Junk'], [suchNachricht(3, 'Spam', '2026-09-04T10:00:00+00:00')]),
        suchOrdner('Entw&APw-rfe', ['\\Drafts'], [suchNachricht(4, 'Nie abgeschickt', '2026-09-05T10:00:00+00:00')]),
        suchOrdner('Archiv', [], [suchNachricht(5, 'Treffer Archiv', '2026-09-01T10:00:00+00:00')]),
    ]);

    it('laesst Papierkorb, Spam und Entwuerfe aus — an der Kennzeichnung, nicht am Namen', function () use ($postfach) {
        // O365 liefert Ordnernamen UTF-7-kodiert; ein Namensvergleich auf
        // „geloescht" liefe dort ins Leere. Am echten Postfach gemessen: 10 von
        // 50 Treffern kamen aus dem Papierkorb, bevor das ueber die Flags lief.
        [$zeilen, $gesamt, $durchsucht] = suchKlient($postfach())->searchAll(Criteria::fromArray(['text' => 'x']));

        expect(array_column($zeilen, 'subject'))->toBe(['Treffer Eingang', 'Treffer Archiv'])
            ->and($gesamt)->toBe(2)
            ->and($durchsucht)->toBe(2);
    });

    it('erkennt den Papierkorb an der Kennzeichnung, auch wenn der Name harmlos ist', function () {
        // Der Fall, den der Namensvergleich NICHT loest: ein Ordner heisst
        // „Ablage 2026" und traegt trotzdem \Trash.
        $klient = suchKlient(suchPostfach([
            suchOrdner('Ablage 2026', ['\\Trash'], [suchNachricht(1, 'Weggeworfen', '2026-09-02T10:00:00+00:00')]),
            suchOrdner('Archiv', [], [suchNachricht(2, 'Treffer', '2026-09-01T10:00:00+00:00')]),
        ]));

        [$zeilen, , $durchsucht] = $klient->searchAll(Criteria::fromArray(['text' => 'x']));

        expect(array_column($zeilen, 'subject'))->toBe(['Treffer'])
            ->and($durchsucht)->toBe(1);
    });

    it('erkennt ihn auch am kodierten Namen, wenn der Server keine Kennzeichen liefert', function () {
        // Der umgekehrte Fall: Server ohne Special-Use. Dann bleibt nur der
        // Name — und O365 liefert ihn UTF-7-kodiert.
        $klient = suchKlient(suchPostfach([
            suchOrdner('Gel&APY-schte Elemente', [], [suchNachricht(1, 'Weggeworfen', '2026-09-02T10:00:00+00:00')]),
            suchOrdner('Archiv', [], [suchNachricht(2, 'Treffer', '2026-09-01T10:00:00+00:00')]),
        ]));

        [$zeilen, , $durchsucht] = $klient->searchAll(Criteria::fromArray(['text' => 'x']));

        expect(array_column($zeilen, 'subject'))->toBe(['Treffer'])
            ->and($durchsucht)->toBe(1);
    });

    it('haengt an jede Zeile den Ordner, in dem sie gefunden wurde', function () use ($postfach) {
        [$zeilen] = suchKlient($postfach())->searchAll(Criteria::fromArray(['text' => 'x']));

        expect($zeilen[0]['folder'])->toBe('INBOX')
            ->and($zeilen[1]['folder'])->toBe('Archiv');
    });

    it('laesst einen gesperrten Ordner die Suche nicht beenden', function () {
        // Und er zaehlt nicht mit — die Zahl steht in der Oberfläche.
        $klient = suchKlient(suchPostfach([
            suchOrdner('INBOX', [], [suchNachricht(1, 'Da', '2026-09-02T10:00:00+00:00')]),
            suchOrdner('Kaputt', [], [], sperrt: true),
            suchOrdner('Archiv', [], [suchNachricht(2, 'Auch da', '2026-09-01T10:00:00+00:00')]),
        ]));

        [$zeilen, , $durchsucht] = $klient->searchAll(Criteria::fromArray(['text' => 'x']));

        expect($zeilen)->toHaveCount(2)->and($durchsucht)->toBe(2);
    });

    it('deckelt je Ordner und schneidet dann auf die Gesamtzahl', function () {
        $klient = suchKlient(suchPostfach([
            suchOrdner('INBOX', [], [
                suchNachricht(1, 'A', '2026-09-05T10:00:00+00:00'),
                suchNachricht(2, 'B', '2026-09-04T10:00:00+00:00'),
                suchNachricht(3, 'C', '2026-09-03T10:00:00+00:00'),
            ]),
        ]));

        [$zeilen, $gesamt] = $klient->searchAll(Criteria::fromArray(['text' => 'x']), limit: 1, perFolder: 2);

        expect($zeilen)->toHaveCount(1)->and($gesamt)->toBe(2);
    });
});

describe('Suche (JMAP)', function () {
    it('sucht in einem Ordner mit inMailbox plus den Angaben', function () {
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/query' => [
                ['Email/query', ['ids' => ['m1'], 'total' => 3], 'q0'],
                ['Email/get', ['list' => [['id' => 'm1', 'subject' => 'Angebot', 'attachments' => []]]], 'g0'],
            ],
        ], $protokoll);

        [$zeilen, $gesamt] = $klient->batch(fn ($p) => $p->search('Inbox', Criteria::fromArray(['text' => 'Angebot'])));

        $filter = collect($protokoll)->last()['payload']['methodCalls'][0][1]['filter'];

        expect($filter)->toBe(['text' => 'Angebot', 'inMailbox' => 'a'])
            ->and($gesamt)->toBe(3)
            ->and($zeilen[0]['subject'])->toBe('Angebot');
    });

    it('sucht ueber alle Ordner in EINEM Aufruf — per inMailboxOtherThan', function () {
        // IMAP muss Ordner fuer Ordner laufen, JMAP nennt die Ausnahmen.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/query' => [
                ['Email/query', ['ids' => ['m1'], 'total' => 1], 'q0'],
                ['Email/get', ['list' => [[
                    'id' => 'm1', 'subject' => 'Treffer', 'attachments' => [], 'mailboxIds' => ['j' => true],
                ]]], 'g0'],
            ],
        ], $protokoll);

        [$zeilen, $gesamt, $durchsucht] = $klient->batch(
            fn ($p) => $p->searchAll(Criteria::fromArray(['text' => 'Treffer']))
        );

        $aufrufe = collect($protokoll)->last()['payload']['methodCalls'];
        $filter = $aufrufe[0][1]['filter'];

        expect($aufrufe)->toHaveCount(2)
            ->and($filter['text'])->toBe('Treffer')
            // b = Deleted Items (role trash) ist der einzige ausgeschlossene
            // Ordner im Fake.
            ->and($filter['inMailboxOtherThan'])->toBe(['b'])
            ->and($gesamt)->toBe(1)
            ->and($durchsucht)->toBe(4)
            // Der Ordner der Zeile kommt aus mailboxIds, nicht aus der Abfrage.
            ->and($zeilen[0]['folder'])->toBe('Archives/2025');
    });

    it('weist eine leere Suche genauso ab wie IMAP', function () {
        expect(fn () => jmapKlient()->searchAll(Criteria::fromArray([])))
            ->toThrow(InvalidArgumentException::class, 'every message');
    });
});

it('gibt in beiden Transporten dieselben Felder zurueck', function () {
    // Der Vertrag: eine Suchzeile sieht aus wie eine Listenzeile, sonst muesste
    // die Oberfläche wissen, woher sie kommt.
    $imap = suchKlient(suchPostfach([suchOrdner('INBOX', [], [suchNachricht(1, 'A', '2026-09-01T10:00:00+00:00')])]));
    [$imapZeilen] = $imap->search('INBOX', Criteria::fromArray(['text' => 'A']));

    $jmap = jmapKlient([
        'Mailbox/get' => [jmapOrdner()],
        'Email/query' => [
            ['Email/query', ['ids' => ['m1'], 'total' => 1], 'q0'],
            ['Email/get', ['list' => [['id' => 'm1', 'subject' => 'A', 'attachments' => []]]], 'g0'],
        ],
    ]);
    [$jmapZeilen] = $jmap->search('Inbox', Criteria::fromArray(['text' => 'A']));

    // Der lose Formatierer im IMAP-Fake liefert bewusst weniger Felder; was er
    // liefert, muss die JMAP-Seite aber auch haben.
    foreach (array_keys($imapZeilen[0]) as $feld) {
        expect($jmapZeilen[0])->toHaveKey($feld);
    }
});
