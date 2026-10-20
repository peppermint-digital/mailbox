<?php

use Illuminate\Database\Eloquent\Model;
use Peppermint\Mailbox\Werkzeuge\Ergebnis;
use Peppermint\Mailbox\Werkzeuge\Vorgang;
use Peppermint\Mailbox\Werkzeuge\Vorgangswerkzeug;
use Peppermint\Mailbox\Werkzeuge\Werkzeug;
use Peppermint\Mailbox\Werkzeuge\Werkzeugverzeichnis;

/**
 * Zwei Schloesser am Ausloesen eines eigenen Verbs (20.10.2026).
 *
 * ## Woher dieser Test kommt
 *
 * Der Waechter `McpWriteToolsAreAuthorizedTest` im Projekt-Manager hat
 * angeschlagen: `RunMailboxActionTool` — das Werkzeug, mit dem ein FREMDES
 * System ein Verb an einer Mail ausloest — enthielt keine sichtbare
 * Rechtepruefung. Gemeldet von der Session calendar-26.
 *
 * Die Pruefung gab es, aber eine Schicht tiefer: in jedem Verb, in seinem
 * eigenen `ausfuehren()`. Das Verzeichnis dazwischen liess durch, und zwar
 * ausdruecklich — mit der Begruendung, eine Pruefung an zwei Stellen sei eine,
 * auf die sich jemand faelschlich verlaesst.
 *
 * Das Argument war zu duenn. Das Verzeichnis ist der ENGPASS: Jeder Aufruf aus
 * einem Nachbarsystem laeuft hier durch. Ein neues Verb, dessen Autor seine
 * Pruefung vergisst, oeffnet sonst ein Loch, das aus drei anderen Systemen
 * erreichbar ist.
 *
 * Also beide Schloesser. Dieser Test haelt das des VERZEICHNISSES fest.
 *
 * ## Und was er ausdruecklich NICHT abnimmt
 *
 * Jedes Verb behaelt seine eigene Pruefung in `ausfuehren()`. Das Verzeichnis
 * ist nicht der einzige Weg zu einem Verb: Die HTTP-Werkzeugleiste jedes
 * Produkts geht ueber `guardWerkzeuge()`, und ein Produkt kann ein Verb auch
 * direkt aus dem Container holen. Belegt wird diese Pflicht je Produkt in
 * dessen `MailWerkzeugeTest` — nicht hier.
 *
 * Das steht in diesem Kopf und nicht als uebersprungener Test darunter: Ein
 * Test, der `true` behauptet, sieht wie Abdeckung aus und ist keine.
 */

/** Ein Verb, das mitschreibt, ob es trotz fehlender Rechte gerufen wurde. */
class MitgeschriebenesVerb implements Vorgangswerkzeug
{
    public bool $gerufen = false;

    public function __construct(
        private bool $darf,
        private ?string $verweis = null,
    ) {}

    public function werkzeug(): Werkzeug
    {
        return new Werkzeug(schluessel: 'probe', wort: 'Probe', symbol: 'flask', reihenfolge: 1, gruppe: 'test');
    }

    public function anwendbar(Vorgang $vorgang, Model $person): bool
    {
        return $this->darf;
    }

    public function zustand(Vorgang $vorgang, Model $person): ?array
    {
        return null;
    }

    public function verweis(Vorgang $vorgang, Model $person): ?string
    {
        return $this->verweis;
    }

    public function ausfuehren(Vorgang $vorgang, array $daten, Model $person): Ergebnis
    {
        $this->gerufen = true;

        return Ergebnis::gelungen('getan');
    }
}

function pruefVorgang(): Vorgang
{
    return new Vorgang(
        postfach: 'department@example.test',
        kette: 'kette-1',
        nachricht: '<a@example.test>',
        betreff: 'Angebot erbeten',
        absender: 'kunde@example.test',
    );
}

function pruefPerson(): Model
{
    return new class extends Model
    {
        protected $table = 'users';

        public function getKey()
        {
            return 7;
        }
    };
}

function verzeichnisMit(Vorgangswerkzeug $verb): Werkzeugverzeichnis
{
    $verzeichnis = new Werkzeugverzeichnis(app());
    $verzeichnis->vergessen();
    $verzeichnis->anmelden($verb);

    return $verzeichnis;
}

test('ein Verb, das fuer diese Person nicht anwendbar ist, wird NICHT gerufen', function () {
    /*
     * Der eigentliche Riegel. Vorher lief der Aufruf durch bis ins Verb — und
     * verliess sich darauf, dass JEDES Verb seine Rechte selbst prueft.
     */
    $verb = new MitgeschriebenesVerb(darf: false);

    $ergebnis = verzeichnisMit($verb)->ausfuehren('probe', pruefVorgang(), [], pruefPerson());

    expect($verb->gerufen)->toBeFalse()
        ->and($ergebnis->gelungen)->toBeFalse();
});

test('die Absage nennt NICHT, ob es am Recht oder am Postfach lag', function () {
    /*
     * Dieselbe Antwort fuer „darf nicht" und „gibt es hier nicht". Die Regel
     * steht in `TicketAusMail` und gilt hier genauso: Wer ein Postfach nicht
     * sehen darf, soll nicht erfahren, dass es existiert.
     *
     * Eine genauere Absage waere ein Werkzeug, mit dem man von aussen
     * herausfindet, welche Postfaecher ein System kennt.
     */
    $ergebnis = verzeichnisMit(new MitgeschriebenesVerb(darf: false))
        ->ausfuehren('probe', pruefVorgang(), [], pruefPerson());

    // `gelungen === false` zuerst, und das ist nicht Dekoration: Ohne diese
    // Zeile besteht der Test auch dann, wenn der Riegel fehlt und der
    // ERFOLGSWEG lief — dessen Meldung enthaelt die Woerter naemlich auch
    // nicht. Bei der Gegenprobe ist genau das aufgefallen.
    expect($ergebnis->gelungen)->toBeFalse()
        ->and($ergebnis->wort)->not->toContain('Recht')
        ->and($ergebnis->wort)->not->toContain('Postfach')
        ->and($ergebnis->wort)->not->toContain('darf');
});

test('ein anwendbares Verb laeuft weiter durch', function () {
    // Die Gegenprobe. Ohne sie waere ein Riegel, der alles verweigert, grün.
    $verb = new MitgeschriebenesVerb(darf: true);

    $ergebnis = verzeichnisMit($verb)->ausfuehren('probe', pruefVorgang(), [], pruefPerson());

    expect($verb->gerufen)->toBeTrue()
        ->and($ergebnis->gelungen)->toBeTrue();
});

test('der Riegel sitzt VOR der Verweis-Regel, nicht dahinter', function () {
    /*
     * Ein Verweis-Verb wird nie ausgeloest; das Verzeichnis sagt das schon
     * vorher. Steht die Verweis-Regel zuerst, bekommt jemand ohne Rechte die
     * Antwort „dieses Verb fuehrt zum Zielsystem" — eine Auskunft darueber,
     * WAS es dort gibt.
     *
     * Also erst die Rechte, dann die Form.
     */
    $ergebnis = verzeichnisMit(new MitgeschriebenesVerb(darf: false, verweis: 'https://ziel.test/x'))
        ->ausfuehren('probe', pruefVorgang(), [], pruefPerson());

    expect($ergebnis->wort)->not->toContain('Zielsystem');
});

test('ein unbekannter Schluessel bleibt unterscheidbar von einer Absage', function () {
    // „Gibt es in diesem System nicht" heisst: Das fragende System ist einen
    // Schritt weiter ausgerollt. Das ist etwas anderes als „du darfst nicht",
    // und die Meldungen muessen verschieden bleiben.
    $verzeichnis = verzeichnisMit(new MitgeschriebenesVerb(darf: true));

    $fremd = $verzeichnis->ausfuehren('gibt-es-nicht', pruefVorgang(), [], pruefPerson());
    $abgelehnt = verzeichnisMit(new MitgeschriebenesVerb(darf: false))
        ->ausfuehren('probe', pruefVorgang(), [], pruefPerson());

    // Beide muessen scheitern — sonst vergleicht der Test eine Absage mit
    // einem Erfolg und besteht auch ohne Riegel.
    expect($fremd->gelungen)->toBeFalse()
        ->and($abgelehnt->gelungen)->toBeFalse()
        ->and($fremd->wort)->not->toBe($abgelehnt->wort);
});
