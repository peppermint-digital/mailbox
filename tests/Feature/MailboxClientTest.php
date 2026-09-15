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

        public array $nachrichten = [];

        public function messages(): object
        {
            return new class($this->nachrichten) {
                public function __construct(private array $nachrichten) {}

                public function find(int $uid): ?object { return $this->nachrichten[$uid] ?? null; }
            };
        }
    };
}

/** Eine Nachricht, die mitschreibt, was mit ihr gemacht wurde. */
function fakeNachricht(): object
{
    return new class
    {
        public array $getan = [];

        public function markSeen(): void { $this->getan[] = 'gelesen'; }

        public function unmarkSeen(): void { $this->getan[] = 'ungelesen'; }

        public function markFlagged(): void { $this->getan[] = 'markiert'; }

        public function unmarkFlagged(): void { $this->getan[] = 'unmarkiert'; }

        public function move(string $ziel, bool $expunge = false): void { $this->getan[] = "verschoben:{$ziel}"; }

        public function delete(bool $expunge = false): void { $this->getan[] = 'endgueltig-geloescht'; }
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


describe('Nachrichten-Aktionen', function () {
    it('markiert als gelesen und wieder als ungelesen', function () {
        $nachricht = fakeNachricht();
        $ordner = fakeOrdner('INBOX', 'INBOX');
        $ordner->nachrichten = [7 => $nachricht];
        $postfach = fakePostfach([$ordner]);

        expect(klient($postfach)->setSeen('INBOX', 7, true))->toBeTrue();
        expect(klient($postfach)->setSeen('INBOX', 7, false))->toBeTrue();
        expect($nachricht->getan)->toBe(['gelesen', 'ungelesen']);
    });

    it('meldet false statt zu werfen, wenn die Nachricht weg ist', function () {
        // Ein Postfach ist geteilt, und Dinge wandern. Wer „gelesen" klickt,
        // waehrend jemand anderes die Mail gerade abgelegt hat, soll nichts
        // passieren sehen — keinen Fehler ueber eine Kennung.
        $ordner = fakeOrdner('INBOX', 'INBOX');
        $postfach = fakePostfach([$ordner]);

        expect(klient($postfach)->setSeen('INBOX', 999, true))->toBeFalse();
    });

    it('setzt und entfernt die Markierung', function () {
        $nachricht = fakeNachricht();
        $ordner = fakeOrdner('INBOX', 'INBOX');
        $ordner->nachrichten = [1 => $nachricht];
        $postfach = fakePostfach([$ordner]);

        klient($postfach)->setFlagged('INBOX', 1, true);

        expect($nachricht->getan)->toBe(['markiert']);
    });
});

describe('Verschieben', function () {
    it('verschiebt in den Zielordner', function () {
        $nachricht = fakeNachricht();
        $quelle = fakeOrdner('INBOX', 'INBOX');
        $quelle->nachrichten = [3 => $nachricht];
        $postfach = fakePostfach([$quelle, fakeOrdner('INBOX.Archiv', 'Archiv')]);

        expect(klient($postfach)->move('INBOX', 3, 'INBOX.Archiv'))->toBeTrue();
        expect($nachricht->getan)->toBe(['verschoben:INBOX.Archiv']);
    });

    it('sagt ja, ohne den Server zu fragen, wenn Quelle und Ziel gleich sind', function () {
        // Kein Fehler: Es muss nichts passieren. Manche Server lehnen es ab,
        // und zwar so, dass es wie ein echter Fehlschlag aussieht.
        $nachricht = fakeNachricht();
        $quelle = fakeOrdner('INBOX', 'INBOX');
        $quelle->nachrichten = [3 => $nachricht];
        $postfach = fakePostfach([$quelle]);

        expect(klient($postfach)->move('INBOX', 3, 'INBOX'))->toBeTrue();
        expect($nachricht->getan)->toBe([]);
    });

    it('meldet false, wenn es den Zielordner nicht gibt', function () {
        $quelle = fakeOrdner('INBOX', 'INBOX');
        $quelle->nachrichten = [3 => fakeNachricht()];
        $postfach = fakePostfach([$quelle]);

        expect(klient($postfach)->move('INBOX', 3, 'Gibtsnicht'))->toBeFalse();
    });
});

describe('Loeschen', function () {
    it('verschiebt in den Papierkorb, wenn es einen gibt', function () {
        // „Loeschen" heisst in einem Mailprogramm „dorthin, wo ich es
        // zurueckholen kann".
        $nachricht = fakeNachricht();
        $posteingang = fakeOrdner('INBOX', 'INBOX');
        $posteingang->nachrichten = [5 => $nachricht];
        $postfach = fakePostfach([$posteingang, fakeOrdner('INBOX.Papierkorb', 'Papierkorb')]);

        expect(klient($postfach)->delete('INBOX', 5))->toBeTrue();
        expect($nachricht->getan)->toBe(['verschoben:INBOX.Papierkorb']);
    });

    it('loescht endgueltig, wenn es keinen Papierkorb gibt', function () {
        $nachricht = fakeNachricht();
        $posteingang = fakeOrdner('INBOX', 'INBOX');
        $posteingang->nachrichten = [5 => $nachricht];
        $postfach = fakePostfach([$posteingang]);

        klient($postfach)->delete('INBOX', 5);

        expect($nachricht->getan)->toBe(['endgueltig-geloescht']);
    });

    it('loescht endgueltig, wenn die Mail schon im Papierkorb liegt', function () {
        // Sonst schoebe man sie dorthin, wo sie schon ist, und der Knopf taete
        // sichtbar nichts.
        $nachricht = fakeNachricht();
        $papierkorb = fakeOrdner('INBOX.Papierkorb', 'Papierkorb');
        $papierkorb->nachrichten = [5 => $nachricht];
        $postfach = fakePostfach([fakeOrdner('INBOX', 'INBOX'), $papierkorb]);

        klient($postfach)->delete('INBOX.Papierkorb', 5);

        expect($nachricht->getan)->toBe(['endgueltig-geloescht']);
    });
});
