<?php

use Peppermint\Mailbox\Contracts\TokenRefresher;
use Peppermint\Mailbox\Imap\MailboxClient;
use Peppermint\Mailbox\Imap\RetryPolicy;
use Peppermint\Mailbox\Imap\SystemFolders;
use Peppermint\Mailbox\Models\MailAccount;

/** Ein Ordner, wie ihn die IMAP-Bibliothek herausgibt. */
function fakeOrdner(string $pfad, string $name, array $flags = [], string $delimiter = '.'): object
{
    return new class($pfad, $name, $flags, $delimiter)
    {
        public array $bewegtNach = [];

        public bool $geloescht = false;

        public function __construct(
            private string $pfad,
            private string $name,
            private array $flags,
            private string $delimiter,
        ) {}

        public function path(): string { return $this->pfad; }

        public function name(): string { return $this->name; }

        public function flags(): array { return $this->flags; }

        public function delimiter(): string { return $this->delimiter; }

        public function move(string $ziel): void { $this->bewegtNach[] = $ziel; }

        public function delete(): void { $this->geloescht = true; }
    };
}

/** Ein Postfach, das mitschreibt, was mit ihm gemacht wurde. */
function fakePostfach(array $ordner, ?array &$protokoll = null): object
{
    return new class($ordner, $protokoll)
    {
        public bool $getrennt = false;

        public array $angelegt = [];

        public array $legeFehlschlagFuer = [];

        public function __construct(private array $ordner, public ?array &$protokoll) {}

        public function folders(): object
        {
            return new class($this) {
                public function __construct(private $postfach) {}

                public function get(): array { return $this->postfach->alleOrdner(); }

                public function create(string $pfad): object
                {
                    return $this->postfach->lege($pfad);
                }
            };
        }

        public function alleOrdner(): array { return $this->ordner; }

        public function lege(string $pfad): object
        {
            if (in_array($pfad, $this->legeFehlschlagFuer, true)) {
                throw new RuntimeException("abgelehnt: {$pfad}");
            }

            $this->angelegt[] = $pfad;
            $neu = fakeOrdner($pfad, $pfad);
            $this->ordner[] = $neu;

            return $neu;
        }

        public function disconnect(): void { $this->getrennt = true; }
    };
}

function klient(object $postfach, ?TokenRefresher $refresher = null, array $kontoWerte = []): MailboxClient
{
    $konto = MailAccount::fromRemote(array_merge([
        'id' => 1, 'email' => 'post@beispiel.de', 'username' => null, 'password' => 'geheim',
        'host' => 'imap.beispiel.de', 'port' => 993, 'encryption' => 'ssl', 'auth_type' => 'password',
        'oauth_access_token' => null, 'oauth_token_expires_at' => null,
    ], $kontoWerte));

    return new MailboxClient(
        account: $konto,
        refresher: $refresher,
        retry: new RetryPolicy(maxRetries: 2, sleeper: fn () => null, jitter: fn () => 0.0),
        connector: fn () => $postfach,
    );
}

describe('die Verbindung', function () {
    it('trennt nach jedem Aufruf wieder', function () {
        // Eine gehaltene Verbindung ueberlebt den Request im Worker, und der
        // naechste Request erbt einen Socket, den der Server laengst zu hat.
        $postfach = fakePostfach([fakeOrdner('INBOX', 'INBOX')]);

        klient($postfach)->folders();

        expect($postfach->getrennt)->toBeTrue();
    });

    it('trennt auch dann, wenn der Aufruf scheitert', function () {
        $postfach = fakePostfach([]);

        try {
            klient($postfach)->session(fn () => throw new RuntimeException('AUTHENTICATIONFAILED'));
        } catch (RuntimeException) {
            // erwartet
        }

        expect($postfach->getrennt)->toBeTrue();
    });
});

describe('die Token-Erneuerung', function () {
    it('erneuert VOR dem Verbinden, wenn das Token bald ablaeuft', function () {
        // Ein abgelaufenes Token sieht von aussen aus wie ein falsches Passwort.
        $gerufen = 0;
        $refresher = new class($gerufen) implements TokenRefresher {
            public function __construct(public int &$gerufen) {}

            public function ensureFresh(MailAccount $account): void { $this->gerufen++; }
        };

        klient(fakePostfach([]), $refresher, [
            'auth_type' => 'oauth',
            'oauth_access_token' => 'alt',
            'oauth_token_expires_at' => now()->addMinute()->toIso8601String(),
        ])->folders();

        expect($gerufen)->toBe(1);
    });

    it('erneuert NICHT, solange das Token noch lange gilt', function () {
        $gerufen = 0;
        $refresher = new class($gerufen) implements TokenRefresher {
            public function __construct(public int &$gerufen) {}

            public function ensureFresh(MailAccount $account): void { $this->gerufen++; }
        };

        klient(fakePostfach([]), $refresher, [
            'auth_type' => 'oauth',
            'oauth_access_token' => 'frisch',
            'oauth_token_expires_at' => now()->addHours(2)->toIso8601String(),
        ])->folders();

        expect($gerufen)->toBe(0);
    });

    it('erneuert bei Passwort-Konten gar nicht', function () {
        $gerufen = 0;
        $refresher = new class($gerufen) implements TokenRefresher {
            public function __construct(public int &$gerufen) {}

            public function ensureFresh(MailAccount $account): void { $this->gerufen++; }
        };

        klient(fakePostfach([]), $refresher)->folders();

        expect($gerufen)->toBe(0);
    });
});

describe('Ordner lesen', function () {
    it('gibt sie als einfache Zeilen heraus', function () {
        $postfach = fakePostfach([
            fakeOrdner('INBOX', 'Posteingang', ['\\HasNoChildren']),
            fakeOrdner('INBOX.Sent', 'Gesendet', ['\\Sent']),
        ]);

        $zeilen = klient($postfach)->folders();

        expect($zeilen)->toHaveCount(2);
        expect($zeilen[1])->toBe(['name' => 'Gesendet', 'path' => 'INBOX.Sent', 'flags' => ['\\Sent']]);
    });
});

describe('Standardordner anlegen', function () {
    it('legt nur an, was fehlt — und unter dem Praefix des Postfachs', function () {
        $postfach = fakePostfach([
            fakeOrdner('INBOX', 'INBOX'),
            fakeOrdner('INBOX.Sent', 'Sent'),
        ]);

        $angelegt = klient($postfach)->ensureStandardFolders();

        expect($angelegt)->toBe(['INBOX.Drafts', 'INBOX.Trash', 'INBOX.Archive']);
    });

    it('nimmt die zweite Schreibweise, wenn der Server die erste ablehnt', function () {
        $postfach = fakePostfach([fakeOrdner('INBOX', 'INBOX'), fakeOrdner('INBOX.Sent', 'Sent')]);
        $postfach->legeFehlschlagFuer = ['INBOX.Drafts'];

        $angelegt = klient($postfach)->ensureStandardFolders();

        expect($angelegt)->toContain('Drafts');
    });

    it('legt nichts an, wenn alles da ist', function () {
        $postfach = fakePostfach([
            fakeOrdner('INBOX', 'INBOX'),
            fakeOrdner('INBOX.Sent', 'Sent'),
            fakeOrdner('INBOX.Drafts', 'Drafts'),
            fakeOrdner('INBOX.Trash', 'Trash'),
            fakeOrdner('INBOX.Archive', 'Archive'),
        ]);

        expect(klient($postfach)->ensureStandardFolders())->toBe([]);
    });
});

describe('Ordner umbenennen und loeschen', function () {
    it('behaelt beim Umbenennen den Elternpfad', function () {
        $ordner = fakeOrdner('INBOX.Projekte.2026', '2026', [], '.');
        $postfach = fakePostfach([fakeOrdner('INBOX', 'INBOX'), $ordner]);

        $ziel = klient($postfach)->renameFolder('INBOX.Projekte.2026', '2027');

        expect($ziel)->toBe('INBOX.Projekte.2027');
        expect($ordner->bewegtNach)->toBe(['INBOX.Projekte.2027']);
    });

    it('weigert sich bei einem Systemordner, statt den Server ablehnen zu lassen', function () {
        // Ein Knopf, den der Server danach zurueckweist, ist schlechter als
        // keiner: Die Person wartet und bekommt einen fremden Wortlaut.
        $postfach = fakePostfach([fakeOrdner('INBOX.Sent', 'Gesendet', ['\\Sent'])]);

        expect(fn () => klient($postfach)->deleteFolder('INBOX.Sent'))
            ->toThrow(RuntimeException::class, 'System folders');
    });

    it('weigert sich beim Posteingang immer', function () {
        $postfach = fakePostfach([fakeOrdner('INBOX', 'INBOX')]);

        expect(fn () => klient($postfach)->renameFolder('INBOX', 'Anderes'))
            ->toThrow(RuntimeException::class, 'System folders');
    });

    it('sagt deutlich, wenn es den Ordner nicht gibt', function () {
        $postfach = fakePostfach([fakeOrdner('INBOX', 'INBOX')]);

        expect(fn () => klient($postfach)->deleteFolder('Gibtsnicht'))
            ->toThrow(RuntimeException::class, 'Folder not found');
    });

    it('loescht einen gewoehnlichen Ordner', function () {
        $ordner = fakeOrdner('INBOX.Alt', 'Alt');
        $postfach = fakePostfach([fakeOrdner('INBOX', 'INBOX'), $ordner]);

        klient($postfach)->deleteFolder('INBOX.Alt');

        expect($ordner->geloescht)->toBeTrue();
    });
});

describe('welche Ordner geschuetzt sind', function () {
    it('schuetzt INBOX ohne Ruecksicht auf Flags', function () {
        expect(SystemFolders::isProtected('INBOX', 'Posteingang', []))->toBeTrue();
        expect(SystemFolders::isProtected('inbox', 'x', []))->toBeTrue();
    });

    it('schuetzt, was der Server selbst markiert hat', function () {
        expect(SystemFolders::isProtected('Kladde', 'Kladde', ['\\Drafts']))->toBeTrue();
    });

    it('laesst einen selbstgebauten Ordner in Ruhe', function () {
        expect(SystemFolders::isProtected('Projekte/2026', '2026', ['\\HasNoChildren']))->toBeFalse();
    });
});
