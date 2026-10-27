<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Contracts\VerwirftKonten;
use Peppermint\Mailbox\Exceptions\AnmeldungAbgelehnt;
use Peppermint\Mailbox\Imap\MailboxClient;
use Peppermint\Mailbox\Imap\RetryPolicy;
use Peppermint\Mailbox\Jmap\JmapClient;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Stores\BrainAccountStore;

/**
 * Bug #1098: Ein zwischengespeichertes Zugriffstoken, das nicht mehr gilt.
 *
 * Die Lage, die hier geprüft wird: Die Konten liegen zentral, das Produkt hält
 * sie 15 Minuten vor, und darin steckt ein Token. Läuft es vorzeitig ab, läuft
 * jeder Zugriff aus diesem Zwischenspeicher in eine abgelehnte Anmeldung —
 * ohne dass irgendetwas den Stand verwirft.
 */

/** Ein Konto, wie es aus der Mitte kommt. */
function auffrischungKonto(string $token, int $id = 7, ?int $besitzer = 3): MailAccount
{
    return MailAccount::fromRemote([
        'id' => $id,
        'user_id' => $besitzer,
        'email' => 'henneberg@beispiel.test',
        'auth_type' => 'oauth',
        'protocol' => 'imap',
        'imap_host' => 'outlook.office365.com',
        'imap_port' => 993,
        'imap_ssl' => true,
        'username' => 'henneberg@beispiel.test',
        'oauth_access_token' => $token,
    ]);
}

/** Eine Ablage, die verwerfen kann und mitzählt, wie oft gefragt wurde. */
function auffrischungAblage(array $tokenFolge): object
{
    return new class($tokenFolge) implements AccountStore, VerwirftKonten
    {
        public int $verworfen = 0;

        public array $besitzer = [];

        public int $gelesen = 0;

        public function __construct(private array $tokenFolge) {}

        public function all(?int $ownerId = null): Collection
        {
            $this->gelesen++;
            $token = array_shift($this->tokenFolge) ?? 'leer';

            return collect([auffrischungKonto($token)]);
        }

        public function find(string|int $id): ?MailAccount
        {
            return $this->all()->firstWhere('id', $id);
        }

        public function isWritable(): bool
        {
            return false;
        }

        public function verwerfen(?int $ownerId = null): void
        {
            $this->verworfen++;
            $this->besitzer[] = $ownerId;
        }
    };
}

/**
 * Ein Postfach, das die ersten `$ablehnungen` Verbindungen mit einer
 * Anmeldeablehnung quittiert und danach funktioniert.
 */
function auffrischungVerbinder(int $ablehnungen, array &$gesehen): Closure
{
    return function (array $settings) use (&$ablehnungen, &$gesehen): object {
        $gesehen[] = $settings['password'] ?? null;

        if ($ablehnungen > 0) {
            $ablehnungen--;

            throw new RuntimeException(
                'IMAP command "TAG1 AUTHENTICATE [redacted] [redacted]" failed. '
                .'Response: "TAG1 NO AUTHENTICATE failed."'
            );
        }

        return new class
        {
            public function folders(): object
            {
                return new class
                {
                    public function get(): array
                    {
                        return [];
                    }
                };
            }

            public function disconnect(): void {}
        };
    };
}

/** Das Sitzungsdokument, wie ein JMAP-Server es herausgibt. */
function auffrischungJmapSitzung(): array
{
    return [
        'apiUrl' => 'https://mail.example.test/jmap/',
        'downloadUrl' => 'https://mail.example.test/jmap/download/{accountId}/{blobId}/{name}?accept={type}',
        'uploadUrl' => 'https://mail.example.test/jmap/upload/{accountId}/',
        'primaryAccounts' => ['urn:ietf:params:jmap:mail' => 'c'],
        'accounts' => ['c' => ['name' => 'info@example.test']],
    ];
}

/** Eine Ordnerantwort, so knapp wie der Test sie braucht. */
function auffrischungJmapOrdner(): array
{
    return ['Mailbox/get', ['list' => [
        ['id' => 'a', 'name' => 'Inbox', 'parentId' => null, 'role' => 'inbox'],
    ]], 'f0'];
}

/** Ohne Wartezeiten, sonst dauert der Test so lange wie ein echter Ausfall. */
function auffrischungOhneWarten(): RetryPolicy
{
    return new RetryPolicy(maxRetries: 1, sleeper: fn () => null, jitter: fn () => 0.0);
}

describe('was als abgelehnte Anmeldung gilt', function () {
    it('erkennt die Antwort eines IMAP-Servers', function () {
        expect(AnmeldungAbgelehnt::trifftZu(new RuntimeException(
            'IMAP command "TAG1 AUTHENTICATE [redacted]" failed. Response: "TAG1 NO AUTHENTICATE failed."'
        )))->toBeTrue();
    });

    it('erkennt die Antwort eines JMAP-Servers', function () {
        expect(AnmeldungAbgelehnt::trifftZu(new RuntimeException(
            'JMAP request to https://beispiel.test/jmap failed with HTTP 401.'
        )))->toBeTrue();
    });

    it('haelt einen Leitungsfehler NICHT fuer eine Ablehnung', function () {
        // Sonst wirft ein zuckendes Netz einen gesunden Kontenstand weg, den
        // der naechste Aufruf teuer neu holt.
        expect(AnmeldungAbgelehnt::trifftZu(new RuntimeException('Connection timed out')))->toBeFalse();
    });

    it('haelt HTTP 403 NICHT fuer eine Ablehnung', function () {
        // „Du darfst das nicht" ist nicht „wer bist du". Ein frisches Token
        // aendert daran nichts, der zweite Versuch waere aussichtslos.
        expect(AnmeldungAbgelehnt::trifftZu(new RuntimeException(
            'JMAP request to https://beispiel.test/jmap failed with HTTP 403.'
        )))->toBeFalse();
    });

    it('sieht auch in die Ursache, nicht nur in die Verpackung', function () {
        $innen = new RuntimeException('NO AUTHENTICATE failed');

        expect(AnmeldungAbgelehnt::trifftZu(new RuntimeException('Request failed', 0, $innen)))->toBeTrue();
    });
});

describe('der zweite Versuch nach einer Ablehnung', function () {
    it('verwirft den Stand, liest neu und kommt durch', function () {
        $gesehen = [];
        $ablage = auffrischungAblage(['frisch']);

        $client = new MailboxClient(
            account: auffrischungKonto('alt'),
            retry: auffrischungOhneWarten(),
            connector: auffrischungVerbinder(1, $gesehen),
            ablage: $ablage,
        );

        expect($client->folders())->toBe([]);

        // Verworfen wurde genau einmal, und zwar fuer den Besitzer des Kontos
        // — die besitzerlose Liste raeumt der Store selbst mit weg.
        expect($ablage->verworfen)->toBe(1)
            ->and($ablage->besitzer)->toBe([3]);

        // Und der zweite Versuch ging mit dem NEUEN Token hinaus.
        expect($gesehen)->toBe(['alt', 'frisch']);
    });

    it('versucht es genau einmal, nicht zweimal', function () {
        // Die Mitte praegt bei jedem Lesen ein neues Token. Ohne Begrenzung
        // saehe jeder Durchgang eine Aenderung und liefe weiter.
        $gesehen = [];
        $ablage = auffrischungAblage(['frisch', 'noch-frischer', 'und-noch-eins']);

        $client = new MailboxClient(
            account: auffrischungKonto('alt'),
            retry: auffrischungOhneWarten(),
            connector: auffrischungVerbinder(99, $gesehen),
            ablage: $ablage,
        );

        expect(fn () => $client->folders())->toThrow(RuntimeException::class, 'AUTHENTICATE failed');

        expect($ablage->verworfen)->toBe(1)
            ->and($gesehen)->toBe(['alt', 'frisch']);
    });

    it('laesst alles wie bisher, wenn keine Ablage mitgegeben ist', function () {
        // Der Fall jedes Produkts mit eigener Kontotabelle: Es gibt nichts zu
        // verwerfen, und der Fehler geht unveraendert durch.
        $gesehen = [];

        $client = new MailboxClient(
            account: auffrischungKonto('alt'),
            retry: auffrischungOhneWarten(),
            connector: auffrischungVerbinder(99, $gesehen),
        );

        expect(fn () => $client->folders())->toThrow(RuntimeException::class, 'AUTHENTICATE failed');
        expect($gesehen)->toBe(['alt']);
    });

    it('laesst alles wie bisher, wenn die Ablage nicht verwerfen kann', function () {
        // Eine Ablage ohne die Schnittstelle ist der Normalfall — `instanceof`
        // entscheidet, nicht die Anwesenheit einer Ablage.
        $gesehen = [];

        $ohneVerwerfen = new class implements AccountStore
        {
            public function all(?int $ownerId = null): Collection
            {
                return collect([auffrischungKonto('frisch')]);
            }

            public function find(string|int $id): ?MailAccount
            {
                return auffrischungKonto('frisch');
            }

            public function isWritable(): bool
            {
                return true;
            }
        };

        $client = new MailboxClient(
            account: auffrischungKonto('alt'),
            retry: auffrischungOhneWarten(),
            connector: auffrischungVerbinder(99, $gesehen),
            ablage: $ohneVerwerfen,
        );

        expect(fn () => $client->folders())->toThrow(RuntimeException::class, 'AUTHENTICATE failed');
        expect($gesehen)->toBe(['alt']);
    });

    it('ruehrt den Stand bei einem Leitungsfehler nicht an', function () {
        $gesehen = [];
        $ablage = auffrischungAblage(['frisch']);

        $verbinder = function (array $settings) use (&$gesehen): object {
            $gesehen[] = $settings['password'] ?? null;

            throw new RuntimeException('Connection timed out');
        };

        $client = new MailboxClient(
            account: auffrischungKonto('alt'),
            retry: auffrischungOhneWarten(),
            connector: $verbinder,
            ablage: $ablage,
        );

        expect(fn () => $client->folders())->toThrow(RuntimeException::class, 'timed out');

        // Nichts verworfen — und die Wiederholungen kamen von der RetryPolicy,
        // die dafuer zustaendig ist.
        expect($ablage->verworfen)->toBe(0);
    });

    it('gibt den urspruenglichen Fehler, wenn die Mitte dasselbe Token liefert', function () {
        // Sonst verdoppelt sich bei einem Postfach, dessen Zustimmung wirklich
        // entzogen wurde, jede Anmeldedauer — fuer dieselbe Meldung.
        $gesehen = [];
        $ablage = auffrischungAblage(['alt']);

        $client = new MailboxClient(
            account: auffrischungKonto('alt'),
            retry: auffrischungOhneWarten(),
            connector: auffrischungVerbinder(99, $gesehen),
            ablage: $ablage,
        );

        expect(fn () => $client->folders())->toThrow(RuntimeException::class, 'AUTHENTICATE failed');

        expect($ablage->verworfen)->toBe(1)
            ->and($gesehen)->toBe(['alt']);
    });
});

describe('die Ablage vergisst wirklich alles', function () {
    beforeEach(fn () => Cache::flush());

    it('raeumt die Liste des Besitzers UND die besitzerlose', function () {
        // Das Konto steht in beiden. Nur `.all` zu vergessen waere der halbe
        // Treffer, der spaeter niemandem auffaellt.
        $store = new BrainAccountStore(fn () => ['data' => [['id' => 7, 'email' => 'a@b.test']]], 900);

        $store->all();
        $store->all(3);

        expect(Cache::has('mailbox.brain.accounts.all'))->toBeTrue()
            ->and(Cache::has('mailbox.brain.accounts.3'))->toBeTrue();

        $store->verwerfen(3);

        expect(Cache::has('mailbox.brain.accounts.all'))->toBeFalse()
            ->and(Cache::has('mailbox.brain.accounts.3'))->toBeFalse();
    });

    it('raeumt ohne Besitzer-Angabe jede gespeicherte Liste', function () {
        $store = new BrainAccountStore(fn () => ['data' => [['id' => 7, 'email' => 'a@b.test']]], 900);

        $store->all();
        $store->all(3);
        $store->all(9);

        $store->verwerfen();

        expect(Cache::has('mailbox.brain.accounts.all'))->toBeFalse()
            ->and(Cache::has('mailbox.brain.accounts.3'))->toBeFalse()
            ->and(Cache::has('mailbox.brain.accounts.9'))->toBeFalse();
    });

    it('fragt nach dem Verwerfen wirklich wieder die Mitte', function () {
        // Der Zweck der ganzen Uebung: Der naechste Lesevorgang darf nicht
        // aus dem Speicher bedient werden.
        $aufrufe = 0;
        $store = new BrainAccountStore(function () use (&$aufrufe) {
            $aufrufe++;

            return ['data' => [['id' => 7, 'email' => 'a@b.test']]];
        }, 900);

        $store->all();
        $store->all();
        expect($aufrufe)->toBe(1);

        $store->verwerfen();
        $store->all();

        expect($aufrufe)->toBe(2);
    });
});

describe('derselbe Weg bei JMAP', function () {
    it('verwirft den Stand, liest neu und kommt durch', function () {
        // Gleiche Logik, gleicher Nachweis wie bei IMAP — die Luecke sass an
        // derselben Stelle in beiden Transporten, und zwei Umsetzungen, die
        // nur fast gleich sind, laufen still auseinander.
        $gesehen = [];
        $ablehnungen = 1;

        $ablage = new class implements AccountStore, VerwirftKonten
        {
            public int $verworfen = 0;

            public function all(?int $ownerId = null): Collection
            {
                return collect([$this->find(7)]);
            }

            public function find(string|int $id): ?MailAccount
            {
                return MailAccount::fromRemote([
                    'id' => 7, 'user_id' => 3, 'email' => 'info@example.test',
                    'protocol' => 'jmap', 'imap_host' => 'mail.example.test',
                    'auth_type' => 'oauth', 'oauth_access_token' => 'frisch',
                ]);
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function verwerfen(?int $ownerId = null): void
            {
                $this->verworfen++;
            }
        };

        $sender = function (string $method, string $url, array $payload, array $headers) use (&$gesehen, &$ablehnungen): array {
            $gesehen[] = $headers['Authorization'] ?? null;

            if ($ablehnungen > 0) {
                $ablehnungen--;

                throw new RuntimeException("JMAP request to {$url} failed with HTTP 401.");
            }

            if ($method === 'GET' && str_contains($url, '/.well-known/jmap')) {
                return auffrischungJmapSitzung();
            }

            return ['methodResponses' => [auffrischungJmapOrdner()]];
        };

        $client = new JmapClient(
            account: MailAccount::fromRemote([
                'id' => 7, 'user_id' => 3, 'email' => 'info@example.test',
                'protocol' => 'jmap', 'imap_host' => 'mail.example.test',
                'auth_type' => 'oauth', 'oauth_access_token' => 'alt',
            ]),
            sender: $sender,
            ablage: $ablage,
        );

        expect($client->folders())->not->toBeEmpty();

        // Verworfen wurde einmal — und der zweite Versuch ging mit dem NEUEN
        // Token hinaus, nicht mit dem abgelehnten.
        expect($ablage->verworfen)->toBe(1)
            ->and($gesehen[0])->toBe('Bearer alt')
            ->and($gesehen[1])->toBe('Bearer frisch');
    });

    it('laesst alles wie bisher, wenn keine Ablage mitgegeben ist', function () {
        $versuche = 0;

        $sender = function (string $method, string $url) use (&$versuche): array {
            $versuche++;

            throw new RuntimeException("JMAP request to {$url} failed with HTTP 401.");
        };

        $client = new JmapClient(
            account: MailAccount::fromRemote([
                'id' => 7, 'email' => 'info@example.test', 'protocol' => 'jmap',
                'imap_host' => 'mail.example.test', 'auth_type' => 'oauth',
                'oauth_access_token' => 'alt',
            ]),
            sender: $sender,
        );

        expect(fn () => $client->folders())->toThrow(RuntimeException::class, 'HTTP 401');

        // Genau ein Versuch: ohne Ablage gibt es nichts aufzufrischen, und ein
        // zweiter waere dieselbe Frage mit denselben Daten.
        expect($versuche)->toBe(1);
    });
});
