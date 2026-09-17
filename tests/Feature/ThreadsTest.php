<?php

use Peppermint\Mailbox\Imap\MailboxClient;
use Peppermint\Mailbox\Imap\MessageFormatter;
use Peppermint\Mailbox\Imap\RetryPolicy;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Unterhaltungen statt Nachrichten (#5844).
 *
 * Auch das lag bisher nur im Manager. Die teuren Stellen sind nicht die
 * Gruppierung — die liegt längst im Paket — sondern das, was drumherum
 * passiert: Wann wird ausgeschlossen, und was wird überhaupt formatiert.
 */

/** Eine Nachricht, wie ImapEngine sie herausgibt — so weit cheapRows sie anfasst. */
function kettenNachricht(int $uid, string $betreff, string $datum, ?string $messageId = null, ?string $inReplyTo = null): object
{
    return new class($uid, $betreff, $datum, $messageId, $inReplyTo)
    {
        public function __construct(
            private int $uid,
            private string $betreff,
            private string $datum,
            private ?string $messageId,
            private ?string $inReplyTo,
        ) {}

        public function uid(): int
        {
            return $this->uid;
        }

        public function subject(): string
        {
            return $this->betreff;
        }

        public function messageId(): ?string
        {
            return $this->messageId;
        }

        public function date(): ?object
        {
            return new class($this->datum)
            {
                public function __construct(private string $d) {}

                public function toIso8601String(): string
                {
                    return $this->d;
                }

                public function getTimestamp(): int
                {
                    return strtotime($this->d) ?: 0;
                }
            };
        }

        public function from(): ?object
        {
            return new class
            {
                public function email(): string
                {
                    return 'kunde@example.test';
                }

                public function name(): string
                {
                    return 'Kunde';
                }
            };
        }

        public function isSeen(): bool
        {
            return true;
        }

        public function isFlagged(): bool
        {
            return false;
        }

        public function header(string $name): ?object
        {
            $wert = $name === 'in-reply-to' ? $this->inReplyTo : null;

            return $wert === null ? null : new class($wert)
            {
                public function __construct(private string $w) {}

                public function getValue(): string
                {
                    return $this->w;
                }
            };
        }
    };
}

/** Zählt mit, wie oft überhaupt formatiert wurde — das ist hier der Messwert. */
function zaehlenderFormatierer(object $zaehler): MessageFormatter
{
    return new class($zaehler) extends MessageFormatter
    {
        public function __construct(private object $zaehler) {}

        public function summary(mixed $message): array
        {
            $this->zaehler->anzahl++;

            return ['uid' => $message->uid(), 'subject' => $message->subject(), 'preview' => 'formatiert'];
        }
    };
}

function kettenKlient(array $nachrichten, ?object $zaehler = null): MailboxClient
{
    $zaehler ??= new class
    {
        public int $anzahl = 0;
    };

    return new MailboxClient(
        account: MailAccount::fromRemote([
            'id' => 1, 'email' => 'post@example.test', 'password' => 'geheim',
            'imap_host' => 'imap.example.test', 'auth_type' => 'password',
            'oauth_access_token' => null, 'oauth_token_expires_at' => null,
        ]),
        retry: new RetryPolicy(maxRetries: 1, sleeper: fn () => null, jitter: fn () => 0.0),
        connector: fn () => suchPostfach([suchOrdner('INBOX', ['\\Inbox'], $nachrichten)]),
        formatter: zaehlenderFormatierer($zaehler),
    );
}

describe('Ketten (IMAP)', function () {
    it('blaettert ueber KETTEN, nicht ueber Nachrichten', function () {
        // Sonst reisst eine Unterhaltung ueber zwei Seiten.
        $nachrichten = [
            kettenNachricht(1, 'Angebot', '2026-09-03T10:00:00+00:00', '<a@x>'),
            kettenNachricht(2, 'Re: Angebot', '2026-09-04T10:00:00+00:00', '<b@x>', '<a@x>'),
            kettenNachricht(3, 'Rechnung', '2026-09-02T10:00:00+00:00', '<c@x>'),
        ];

        [$ketten, $gesamt] = kettenKlient($nachrichten)->threads('INBOX', page: 1, perPage: 25);

        expect($gesamt)->toBe(2)
            ->and($ketten[0]['message_count'])->toBe(2)
            ->and($ketten[0]['subject'])->toBe('Angebot');
    });

    it('formatiert nur die juengste Nachricht der SICHTBAREN Ketten', function () {
        // Der teure Teil: Formatieren fasst den Rumpf an, und ein nicht
        // geladener Rumpf wird ueber IMAP an Ort und Stelle nachgeholt.
        // Hundert formatierte Zeilen sind hundert Abrufe fuer die
        // fuenfundzwanzig, die jemand sieht.
        $zaehler = new class
        {
            public int $anzahl = 0;
        };
        $nachrichten = [];

        for ($i = 1; $i <= 30; $i++) {
            $nachrichten[] = kettenNachricht($i, "Sache {$i}", sprintf('2026-09-%02dT10:00:00+00:00', $i % 28 + 1), "<m{$i}@x>");
        }

        [$ketten, $gesamt] = kettenKlient($nachrichten, $zaehler)->threads('INBOX', page: 1, perPage: 5);

        expect($gesamt)->toBe(30)
            ->and($ketten)->toHaveCount(5)
            // Fuenf sichtbare Ketten, fuenf Formatierungen — nicht dreissig.
            ->and($zaehler->anzahl)->toBe(5);
    });

    it('sortiert weggelegte Ketten VOR dem Blaettern aus', function () {
        // Sonst zaehlt die Gesamtzahl etwas anderes, als die Liste zeigt —
        // am 07.08.2026 meldete Seite 1 vierundzwanzig und Seite 2
        // fuenfundzwanzig.
        $nachrichten = [
            kettenNachricht(1, 'Bleibt', '2026-09-03T10:00:00+00:00', '<a@x>'),
            kettenNachricht(2, 'Weggelegt', '2026-09-04T10:00:00+00:00', '<b@x>'),
        ];

        $klient = kettenKlient($nachrichten);
        [$alle] = $klient->threads('INBOX');
        $weg = collect($alle)->firstWhere('subject', 'Weggelegt')['thread_id'];

        [$ketten, $gesamt] = $klient->threads('INBOX', excludeThreadIds: [$weg]);

        expect($gesamt)->toBe(1)
            ->and($ketten)->toHaveCount(1)
            ->and($ketten[0]['subject'])->toBe('Bleibt');
    });

    it('fragt das Produkt EINMAL nach seinen eigenen Antworten — mit den Schluesseln', function () {
        // Eine gesendete Antwort liegt in der Tabelle des Produkts, nicht
        // zwingend im Ordner. Das Paket kennt die Tabelle nicht, also wird
        // gefragt: einmal, nicht je Kette.
        $nachrichten = [
            kettenNachricht(1, 'Angebot', '2026-09-03T10:00:00+00:00', '<a@x>'),
            kettenNachricht(2, 'Rechnung', '2026-09-02T10:00:00+00:00', '<c@x>'),
        ];

        $gefragt = [];

        kettenKlient($nachrichten)->threads('INBOX', ownReplies: function (array $schluessel) use (&$gefragt): array {
            $gefragt[] = $schluessel;

            return [];
        });

        expect($gefragt)->toHaveCount(1)
            ->and($gefragt[0])->toHaveCount(2);
    });

    it('laesst kein IMAP-Objekt nach draussen', function () {
        // Ein Produkt, das in einer Zeile ein ImapEngine-Objekt findet, benutzt
        // es irgendwann — und kennt damit wieder den Transport.
        [$ketten] = kettenKlient([kettenNachricht(1, 'Angebot', '2026-09-03T10:00:00+00:00', '<a@x>')])
            ->threads('INBOX');

        expect($ketten[0]['messages'][0])->not->toHaveKey('message');
    });

    it('antwortet auf einen unbekannten Ordner leer', function () {
        [$ketten, $gesamt] = kettenKlient([])->threads('Gibt-Es-Nicht');

        expect($ketten)->toBe([])->and($gesamt)->toBe(0);
    });
});

describe('Ketten (JMAP)', function () {
    it('gruppiert nach denselben Regeln wie IMAP', function () {
        // Der Server koennte selbst gruppieren. Er tut es bewusst nicht: Ein
        // JMAP-Postfach, dessen Unterhaltungen anders geschnitten waeren als
        // ein IMAP-Postfach, waere ein Unterschied, den niemand bestellt hat.
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/query' => [
                ['Email/query', ['ids' => ['m1', 'm2'], 'total' => 2], 'q0'],
                ['Email/get', ['list' => [
                    ['id' => 'm1', 'subject' => 'Angebot', 'messageId' => ['a@x'], 'attachments' => [],
                        'receivedAt' => '2026-09-03T10:00:00Z', 'keywords' => ['$seen' => true]],
                    ['id' => 'm2', 'subject' => 'Re: Angebot', 'messageId' => ['b@x'], 'inReplyTo' => ['a@x'],
                        'attachments' => [], 'receivedAt' => '2026-09-04T10:00:00Z', 'keywords' => []],
                ]], 'g0'],
            ],
        ]);

        [$ketten, $gesamt] = $klient->threads('Inbox');

        expect($gesamt)->toBe(1)
            ->and($ketten[0]['message_count'])->toBe(2)
            ->and($ketten[0]['subject'])->toBe('Angebot')
            // Eine ungelesene in der Kette faerbt die ganze Kette.
            ->and($ketten[0]['has_unread'])->toBeTrue();
    });

    it('liefert dieselben Kettenfelder wie IMAP', function () {
        $imap = kettenKlient([
            kettenNachricht(1, 'Angebot', '2026-09-03T10:00:00+00:00', '<a@x>'),
        ])->threads('INBOX')[0][0];

        $jmap = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/query' => [
                ['Email/query', ['ids' => ['m1'], 'total' => 1], 'q0'],
                ['Email/get', ['list' => [['id' => 'm1', 'subject' => 'Angebot', 'messageId' => ['a@x'],
                    'attachments' => [], 'receivedAt' => '2026-09-03T10:00:00Z', 'keywords' => ['$seen' => true]]]], 'g0'],
            ],
        ])->threads('Inbox')[0][0];

        expect(array_keys($jmap))->toBe(array_keys($imap));
    });
});

describe('eine Seite der Liste', function () {
    it('formatiert nur die sichtbaren Zeilen, nicht alle geholten', function () {
        // Bug #877: headerRows() formatierte alle hundert — und formatieren
        // fasst den Rumpf an, den ImapEngine dann einzeln nachholt.
        $zaehler = new class
        {
            public int $anzahl = 0;
        };

        $nachrichten = [];

        for ($i = 1; $i <= 40; $i++) {
            $nachrichten[] = kettenNachricht($i, "Sache {$i}", sprintf('2026-09-%02dT10:00:00+00:00', $i % 28 + 1), "<m{$i}@x>");
        }

        [$zeilen, $gesamt] = kettenKlient($nachrichten, $zaehler)->page('INBOX', page: 1, perPage: 25);

        expect($zeilen)->toHaveCount(25)
            ->and($gesamt)->toBe(40)
            ->and($zaehler->anzahl)->toBe(25);
    });

    it('sortiert nach Datum, nicht nach uid', function () {
        // uid sagt, wann der Server die Mail sah. Ein gestern empfangener
        // alter Brief stuende sonst oben.
        $klient = kettenKlient([
            kettenNachricht(9, 'alt', '2026-01-02T10:00:00+00:00', '<a@x>'),
            kettenNachricht(1, 'neu', '2026-09-03T10:00:00+00:00', '<b@x>'),
        ]);

        [$zeilen] = $klient->page('INBOX');

        expect($zeilen[0]['subject'])->toBe('neu');
    });

    it('entdoppelt im Gesendet-Ordner, sonst nicht', function () {
        // Viele Server legen beim Senden selbst eine Kopie ab, waehrend der
        // Client seine eigene anhaengt: zwei Zeilen, eine Mail.
        $doppelt = [
            kettenNachricht(1, 'Angebot', '2026-09-03T10:00:00+00:00', '<a@x>'),
            kettenNachricht(2, 'Angebot', '2026-09-03T10:00:00+00:00', '<a@x>'),
        ];

        $gesendet = new MailboxClient(
            account: MailAccount::fromRemote([
                'id' => 1, 'email' => 'post@example.test', 'password' => 'geheim',
                'imap_host' => 'imap.example.test', 'auth_type' => 'password',
                'oauth_access_token' => null, 'oauth_token_expires_at' => null,
            ]),
            retry: new RetryPolicy(maxRetries: 1, sleeper: fn () => null, jitter: fn () => 0.0),
            connector: fn () => suchPostfach([suchOrdner('Sent', ['\\Sent'], $doppelt)]),
            formatter: zaehlenderFormatierer(new class
            {
                public int $anzahl = 0;
            }),
        );

        [$imGesendeten] = $gesendet->page('Sent');
        [$imEingang] = kettenKlient($doppelt)->page('INBOX');

        expect($imGesendeten)->toHaveCount(1)
            ->and($imEingang)->toHaveCount(2);
    });

    it('verspricht nicht mehr Seiten, als es Zeilen gibt', function () {
        // Der Server zaehlt 500, geholt wurden 100 — wer 500 meldet, bietet
        // Seiten an, die leer zurueckkommen.
        $klient = kettenKlient([kettenNachricht(1, 'Eine', '2026-09-03T10:00:00+00:00', '<a@x>')]);

        [, $gesamt] = $klient->page('INBOX');

        expect($gesamt)->toBe(1);
    });

    it('macht es ueber JMAP genauso — nur ohne die teure Stelle', function () {
        $klient = jmapKlient([
            'Mailbox/get' => [jmapOrdner()],
            'Email/query' => [
                ['Email/query', ['ids' => ['m1', 'm2'], 'total' => 2], 'q0'],
                ['Email/get', ['list' => [
                    ['id' => 'm1', 'subject' => 'alt', 'messageId' => ['a@x'], 'attachments' => [], 'receivedAt' => '2026-01-02T10:00:00Z'],
                    ['id' => 'm2', 'subject' => 'neu', 'messageId' => ['b@x'], 'attachments' => [], 'receivedAt' => '2026-09-03T10:00:00Z'],
                ]], 'g0'],
            ],
        ]);

        [$zeilen, $gesamt] = $klient->page('Inbox', perPage: 1);

        expect($zeilen)->toHaveCount(1)
            ->and($zeilen[0]['subject'])->toBe('neu')
            ->and($gesamt)->toBe(2);
    });
});
