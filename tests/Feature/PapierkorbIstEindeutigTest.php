<?php

use Peppermint\Mailbox\Folders\FolderNames;
use Peppermint\Mailbox\Imap\MailboxClient;
use Peppermint\Mailbox\Imap\RetryPolicy;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Loeschen heisst „in den Papierkorb" — und sonst heisst es Datenverlust.
 *
 * `MailboxClient::delete()` verschiebt in den Papierkorb und loescht nur dann
 * endgueltig, wenn es keinen gibt. Dieser Rueckfall ist gewollt: Ein Postfach
 * ohne Papierkorb kann die Mail nirgends aufbewahren.
 *
 * Er ist aber nicht zu unterscheiden von „es gibt einen und ich habe seinen
 * Namen nicht erkannt". Genau darum geht es hier: Jeder Papierkorb, den diese
 * Tests nicht finden, ist eine Mail, die jemand verliert — einmal, lautlos.
 *
 * Bis zum 20.10.2026 suchte diese Methode NUR ueber den Namen, obwohl ihr
 * eigener Vertrag die Marke zuerst zusagt und der JMAP-Weg daneben sie schon
 * benutzte.
 */

/** Eine Nachricht, die mitschreibt, was mit ihr geschah. */
function papierkorbNachricht(): object
{
    return new class
    {
        public ?string $verschobenNach = null;

        public bool $endgueltigWeg = false;

        public function move(string $ziel, bool $expunge = false): void
        {
            $this->verschobenNach = $ziel;
        }

        public function delete(bool $expunge = false): void
        {
            $this->endgueltigWeg = true;
        }
    };
}

/**
 * Ein Ordner mit Namen, Marken und genau einer Nachricht.
 *
 * Eigene Attrappe und nicht `suchOrdner()` aus dem SearchTest: Die kennt kein
 * `find()`, und sie um eines zu erweitern, das nur hier gebraucht wird, macht
 * die gemeinsame Attrappe fuer alle unklarer.
 */
function papierkorbOrdner(string $pfad, array $marken = [], ?object $nachricht = null): object
{
    return new class($pfad, $marken, $nachricht)
    {
        public function __construct(
            private string $pfad,
            private array $marken,
            private ?object $nachricht,
        ) {}

        public function path(): string
        {
            return $this->pfad;
        }

        public function name(): string
        {
            // Wie beim Server: der letzte Teil des Pfades.
            $teile = preg_split('/[.\/]/', $this->pfad);

            return (string) end($teile);
        }

        public function flags(): array
        {
            return $this->marken;
        }

        public function messages(): object
        {
            return new class($this->nachricht)
            {
                public function __construct(private ?object $nachricht) {}

                public function find(int|string $uid): ?object
                {
                    return $this->nachricht;
                }
            };
        }
    };
}

function papierkorbKlient(array $ordner): MailboxClient
{
    $postfach = new class($ordner)
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

    return new MailboxClient(
        account: MailAccount::fromRemote([
            'id' => 1, 'email' => 'post@example.test', 'password' => 'geheim',
            'imap_host' => 'imap.example.test', 'auth_type' => 'password',
            'oauth_access_token' => null, 'oauth_token_expires_at' => null,
        ]),
        retry: new RetryPolicy(maxRetries: 1, sleeper: fn () => null, jitter: fn () => 0.0),
        connector: fn () => $postfach,
    );
}

test('die Marke entscheidet, nicht der Name', function () {
    /*
     * Der Ordner heisst „Mulleimer" — kein Name, den irgendeine Liste
     * enthaelt. Die Marke `\Trash` sagt es trotzdem eindeutig, und ohne sie
     * zu lesen waere die Mail endgueltig weg.
     */
    $nachricht = papierkorbNachricht();

    papierkorbKlient([
        papierkorbOrdner('INBOX', [], $nachricht),
        papierkorbOrdner('Mulleimer', ['\\Trash']),
    ])->delete('INBOX', 7);

    expect($nachricht->verschobenNach)->toBe('Mulleimer')
        ->and($nachricht->endgueltigWeg)->toBeFalse();
});

test('ein markierter Ordner weiter unten schlaegt einen Namenstreffer weiter oben', function () {
    /*
     * Dieselbe Begruendung wie in `specialFolder()`: Zwei Durchgaenge, nicht
     * einer. Mit einem einzigen Durchgang haette „Trash" gewonnen, weil der
     * Server ihn zuerst nennt — und das ist hier ein ARCHIV-Unterordner, der
     * zufaellig so heisst.
     */
    $nachricht = papierkorbNachricht();

    papierkorbKlient([
        papierkorbOrdner('INBOX', [], $nachricht),
        papierkorbOrdner('Archiv/Trash'),
        papierkorbOrdner('Deleted Items', ['\\Trash']),
    ])->delete('INBOX', 7);

    expect($nachricht->verschobenNach)->toBe('Deleted Items');
});

test('ohne Marke faengt der Name es auf', function () {
    // Viele Server setzen keine Sonderzweck-Marken. Dann ist der Name alles,
    // was bleibt — deshalb bleibt er als Rueckfall drin.
    $nachricht = papierkorbNachricht();

    papierkorbKlient([
        papierkorbOrdner('INBOX', [], $nachricht),
        papierkorbOrdner('Papierkorb'),
    ])->delete('INBOX', 7);

    expect($nachricht->verschobenNach)->toBe('Papierkorb');
});

test('die kodierte Schreibweise von Office 365 wird gefunden', function () {
    /*
     * Ueber IMAP kommt ein Name mit Umlaut in modifiziertem UTF-7 (RFC 3501)
     * an. Office 365 meldet den deutschen Papierkorb als
     * `Gel&APY-schte Elemente` — ein Vergleich gegen „gelöschte elemente"
     * findet davon nichts.
     *
     * Im Suchweg ist dieser Preis gemessen (10 von 50 Treffern kamen aus dem
     * Papierkorb). Hier waere er hoeher als ein verwirrender Treffer: Die
     * Mail waere weg.
     */
    $nachricht = papierkorbNachricht();

    papierkorbKlient([
        papierkorbOrdner('INBOX', [], $nachricht),
        papierkorbOrdner('Gel&APY-schte Elemente'),
    ])->delete('INBOX', 7);

    expect($nachricht->verschobenNach)->toBe('Gel&APY-schte Elemente')
        ->and($nachricht->endgueltigWeg)->toBeFalse();
});

test('die Schreibweisen, die der Manager von Hand nachschob, sind jetzt Vorgabe', function () {
    // „Deleted" und „Gelöscht" standen im Projekt-Manager als eigene Liste am
    // Aufruf. Sie hier zu fuehren ist, was diese Uebergabe erspart.
    foreach (['Deleted', 'Gelöscht'] as $name) {
        $nachricht = papierkorbNachricht();

        papierkorbKlient([
            papierkorbOrdner('INBOX', [], $nachricht),
            papierkorbOrdner($name),
        ])->delete('INBOX', 7);

        expect($nachricht->verschobenNach)->toBe($name);
    }
});

test('ohne jeden Papierkorb geht die Mail wirklich — das ist die Zusage', function () {
    /*
     * Die Gegenprobe, und sie gehoert dazu. Dieser Zweig ist gewollt: Ein
     * Postfach ohne Papierkorb kann die Mail nirgends aufbewahren, und der
     * Vertrag sagt das zu.
     *
     * Wuerde er wegfallen, taete der Loeschknopf in solchen Postfaechern
     * nichts — ohne Fehlermeldung.
     */
    $nachricht = papierkorbNachricht();

    papierkorbKlient([
        papierkorbOrdner('INBOX', [], $nachricht),
        papierkorbOrdner('Archiv'),
    ])->delete('INBOX', 7);

    expect($nachricht->endgueltigWeg)->toBeTrue()
        ->and($nachricht->verschobenNach)->toBeNull();
});

test('wer schon im Papierkorb liegt, wird nicht im Kreis geschoben', function () {
    $nachricht = papierkorbNachricht();

    papierkorbKlient([
        papierkorbOrdner('Papierkorb', ['\\Trash'], $nachricht),
    ])->delete('Papierkorb', 7);

    expect($nachricht->endgueltigWeg)->toBeTrue()
        ->and($nachricht->verschobenNach)->toBeNull();
});

test('die Liste fuehrt beide Schreibweisen jedes Namens mit Umlaut', function () {
    /*
     * Eine Regel ueber die Liste selbst, damit der naechste Zusatz nicht die
     * halbe Arbeit macht: Wer „gelöscht" eintraegt, muss auch die Form ohne
     * Umlaut und die kodierte mitbringen — sonst findet die Liste genau den
     * Server nicht, der sie braucht.
     */
    $liste = FolderNames::TRASH_ALIASES;

    expect($liste)->toContain('gelöscht')->toContain('geloscht')
        ->and($liste)->toContain('gelöschte elemente')->toContain('geloschte elemente')
        ->and($liste)->toContain('gel&apy-schte elemente');
});

test('alle Schreibweisen sind klein — sonst vergleicht die Pruefung ins Leere', function () {
    // `matchesAny()` senkt den Ordnernamen, nicht die Liste. Ein
    // grossgeschriebener Eintrag hier traefe nie.
    foreach (FolderNames::TRASH_ALIASES as $name) {
        expect($name)->toBe(mb_strtolower($name));
    }
});
