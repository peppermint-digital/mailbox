<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Peppermint\Mailbox\Contracts\Zuweisungsspeicher;
use Peppermint\Mailbox\Http\HandlesAssignments;
use Peppermint\Mailbox\Stores\BrainZuweisungsspeicher;
use Peppermint\Mailbox\Stores\LokalerZuweisungsspeicher;
use Peppermint\Mailbox\Werkzeuge\Vorgang;
use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Zuweisung;
use Peppermint\Mailbox\Zustand\Zuweisungsliste;

/**
 * Zustaendigkeiten in der Mitte (19.10.2026).
 *
 * Geprueft wird das, was still falsch sein kann — und bei DIESEM Merkmal ist
 * die stille Form besonders teuer, weil sie nicht wie ein Fehler aussieht:
 *
 * - Eine unerreichbare Mitte darf nicht „niemand zustaendig" heissen. Sonst
 *   sieht jede Mail frei aus, zwei Leute fangen dieselbe Sache an, und es gibt
 *   keine Meldung.
 * - Ein nicht geschriebenes Zuweisen darf nicht als gelungen gemeldet werden.
 *   Der Zuweisende glaubt sonst, die Sache ist vergeben.
 * - Gefragt werden MUSS nach der Adresse. Mit der oertlichen Nummer kommt die
 *   Zustaendigkeit eines fremden Postfachs zurueck — kein Fehler, falscher
 *   Inhalt.
 */
uses(RefreshDatabase::class);

class TestControllerFuerSpeicher
{
    use HandlesAssignments;

    /** @param list<array{id: int, name: string}> $personen */
    public function __construct(private readonly array $personen) {}

    protected function assignableUsers(int $account): array
    {
        return $this->personen;
    }

    protected function currentUserId(): ?int
    {
        return 7;
    }
}

function probeAnna(): Person
{
    return new Person(adresse: 'anna@example.test', nummer: 3, name: 'Anna Meier');
}

function probeBernd(): Person
{
    return new Person(adresse: 'bernd@example.test', nummer: 4, name: 'Bernd Schulz');
}

function probeIch(): Person
{
    return new Person(adresse: 'ich@example.test', nummer: 7, name: 'Ich Selbst');
}

function speicherAnfrage(array $daten): Request
{
    $r = Request::create('/', 'POST', $daten);
    $r->headers->set('Accept', 'application/json');

    return $r;
}

/**
 * Ein Speicher, der sich merkt, was er gefragt wurde.
 *
 * Als Klasse und nicht als Funktion mit Referenz-Parameter: Eine Referenz, die
 * innerhalb der Funktion neu gebunden wird, loest die Bindung zum Aufrufer —
 * das Protokoll bliebe dann leer, und der Test sagte „es wurde nicht gefragt",
 * obwohl gefragt wurde.
 */
class BrainSpion
{
    /** @var list<array{capability: string, args: array<string, mixed>}> */
    public array $gefragt = [];

    public BrainZuweisungsspeicher $speicher;

    public function __construct(array|false $antwort, ?string $adresse = 'department@peppermint-digital.de')
    {
        $this->speicher = new BrainZuweisungsspeicher(
            function (string $faehigkeit, array $argumente) use ($antwort): ?array {
                $this->gefragt[] = ['capability' => $faehigkeit, 'args' => $argumente];

                if ($antwort === false) {
                    throw new RuntimeException('Zeitueberschreitung.');
                }

                return $antwort;
            },
            fn (int $konto): ?string => $adresse,
        );
    }
}

function brainSpeicher(array|false $antwort, ?string $adresse = 'department@peppermint-digital.de'): BrainZuweisungsspeicher
{
    return (new BrainSpion($antwort, $adresse))->speicher;
}

$personen = [
    ['id' => 3, 'name' => 'Anna Meier', 'email' => 'anna@example.test'],
    ['id' => 4, 'name' => 'Bernd Schulz', 'email' => 'bernd@example.test'],
];

it('fragt die Mitte nach der ADRESSE und nicht nach der oertlichen Nummer', function () {
    // Dieselbe Mailbox hat in jedem System eine andere Nummer. Wer mit der
    // eigenen fragt, bekommt die Zustaendigkeit eines fremden Postfachs — und
    // zwar ohne Fehlermeldung.
    $spion = new BrainSpion(['ok' => true, 'data' => ['assignments' => []]]);

    $spion->speicher->offene(42);

    expect($spion->gefragt[0]['args'])->toBe(['mailbox' => 'department@peppermint-digital.de'])
        ->and($spion->gefragt[0]['capability'])->toBe('mail.assignments.list');
});

it('fragt gar nicht, wenn die Adresse des Postfachs unbekannt ist', function () {
    // Ein Postfach, das dieses System nicht eindeutig benennen kann, darf es
    // auch nicht in der Mitte ansprechen.
    $spion = new BrainSpion(['ok' => true, 'data' => ['assignments' => []]], adresse: null);

    $liste = $spion->speicher->offene(42);

    expect($spion->gefragt)->toBe([])
        ->and($liste->erreichbar)->toBeFalse();
});

it('unterscheidet eine unerreichbare Mitte von „niemand zustaendig"', function () {
    // Die teuerste Verwechslung dieses Merkmals: Beide ergeben eine leere
    // Liste, aber nur eine davon ist eine Aussage.
    $leer = brainSpeicher(['ok' => true, 'data' => ['assignments' => []]])->offene(42);
    $stumm = brainSpeicher(false)->offene(42);

    expect($leer->leer())->toBeTrue()
        ->and($leer->erreichbar)->toBeTrue()
        ->and($stumm->leer())->toBeTrue()
        ->and($stumm->erreichbar)->toBeFalse();
});

it('behandelt eine Ablehnung der Mitte nicht als Erfolg', function () {
    // Geantwortet und abgelehnt ist etwas anderes als nicht erreichbar —
    // fuehrt hier aber zum selben Ergebnis: kein erfundener Erfolg.
    $speicher = brainSpeicher(['ok' => false, 'error' => 'Unbekanntes Postfach.']);

    expect($speicher->zuweisen(42, '<a@b.test>', 'kette-1', probeAnna(), probeIch()))->toBeNull()
        ->and($speicher->aufheben(42, '<a@b.test>', 'kette-1'))->toBeFalse()
        ->and($speicher->offene(42)->erreichbar)->toBeFalse();
});

it('gibt beim Zuweisen zurueck, was ANKAM — nicht, was geschickt wurde', function () {
    // Den eigenen Entwurf zurueckzugeben waere bequem und falsch: Dann stuende
    // in der Oberflaeche, was man schicken wollte.
    $speicher = brainSpeicher(['ok' => true, 'data' => ['assignment' => [
        'message_id' => '<a@b.test>',
        'thread_id' => 'kette-aus-der-mitte',
        'assigned_to' => 'bernd@example.test',
        'status' => 'open',
    ]]]);

    $zuweisung = $speicher->zuweisen(42, '<a@b.test>', 'kette-die-ich-schickte', probeAnna(), probeIch());

    expect($zuweisung->an->adresse)->toBe('bernd@example.test')
        ->and($zuweisung->kette)->toBe('kette-aus-der-mitte')
        // Die oertliche Nummer kommt von hier, nicht aus der Mitte.
        ->and($zuweisung->konto)->toBe(42)
        ->and($zuweisung->postfach)->toBe('department@peppermint-digital.de');
});

it('meldet eine Antwort ohne Zuweisung als Fehlschlag', function () {
    // `ok: true` und keine Zeile: Die Mitte hat zugehoert und nichts
    // geschrieben. Ein Erfolg ohne Inhalt waere ein gruener Haken fuer nichts.
    expect(brainSpeicher(['ok' => true, 'data' => []])->zuweisen(42, '<a@b.test>', null, probeAnna(), probeIch()))->toBeNull();
});

it('laesst eine Zuweisung ohne Nachricht oder Person nicht in die Liste', function () {
    $liste = brainSpeicher(['ok' => true, 'data' => ['assignments' => [
        ['message_id' => '<a@b.test>', 'assigned_to' => 'anna@example.test'],
        ['message_id' => '', 'assigned_to' => 'anna@example.test'],
        ['message_id' => '<c@b.test>'],
        'kein array',
    ]]])->offene(42);

    expect($liste->zuweisungen)->toHaveCount(1)
        ->and($liste->zuweisungen[0]->nachricht)->toBe('<a@b.test>');
});

it('sagt der Oberflaeche, wenn die Zustaendigkeiten nicht zu holen waren', function () use ($personen) {
    // Ohne diese Angabe sieht die Liste aus wie „alles frei".
    app()->instance(Zuweisungsspeicher::class, brainSpeicher(false));

    $antwort = (new TestControllerFuerSpeicher($personen))->assignments(1)->getData(true);

    expect($antwort['assignments'])->toBe([])
        ->and($antwort['reachable'])->toBeFalse();
});

it('antwortet 503 und ruft afterAssign NICHT, wenn nichts geschrieben wurde', function () use ($personen) {
    // Der Haken benachrichtigt. Ihn nach einem Fehlschlag zu rufen heisst:
    // „Dir wurde etwas zugewiesen" fuer eine Zuweisung, die es nicht gibt.
    app()->instance(Zuweisungsspeicher::class, brainSpeicher(false));

    $gesehen = [];

    $controller = new class($personen, $gesehen) extends TestControllerFuerSpeicher
    {
        public function __construct(array $personen, public array &$gesehen)
        {
            parent::__construct($personen);
        }

        protected function afterAssign(Zuweisung $zuweisung, ?int $vorher, array $daten): void
        {
            $this->gesehen[] = $zuweisung->an;
        }
    };

    $antwort = $controller->assign(speicherAnfrage([
        'message_id' => '<a@b.test>',
        'assigned_to_user_id' => 3,
    ]), 1);

    expect($antwort->getStatusCode())->toBe(503)
        ->and($gesehen)->toBe([]);
});

it('antwortet 503 beim Aufheben, das nicht stattfand', function () use ($personen) {
    // Wer sie abgegeben glaubt, sieht nicht mehr hin.
    app()->instance(Zuweisungsspeicher::class, brainSpeicher(false));

    $antwort = (new TestControllerFuerSpeicher($personen))->unassign(speicherAnfrage([
        'message_id' => '<a@b.test>',
    ]), 1);

    expect($antwort->getStatusCode())->toBe(503);
});

it('sagt der Oberflaeche, ob hier ueberhaupt zugewiesen werden kann', function () use ($personen) {
    // Ein Knopf, der sicher scheitert, ist schlechter als keiner.
    app()->instance(Zuweisungsspeicher::class, new class implements Zuweisungsspeicher
    {
        public function schreibbar(): bool
        {
            return false;
        }

        public function offene(int $konto): Zuweisungsliste
        {
            return Zuweisungsliste::mit([]);
        }

        public function fuer(int $konto, string $nachricht, ?string $kette): ?Zuweisung
        {
            return null;
        }

        public function zuweisen(int $konto, string $nachricht, ?string $kette, Person $an, ?Person $von): ?Zuweisung
        {
            return null;
        }

        public function aufheben(int $konto, string $nachricht, ?string $kette): bool
        {
            return false;
        }
    });

    $antwort = (new TestControllerFuerSpeicher($personen))->assignmentUsers(1)->getData(true);

    expect($antwort['writable'])->toBeFalse();
});

it('traegt im lokalen Speicher dieselbe Aussage wie vorher — mit Adresse daneben', function () {
    // Der Haken soll nicht merken, aus welchem Speicher die Aussage kam. Ein
    // `afterAssign()`, das je nach Betriebsart andere Felder vorfindet, waeren
    // zwei Haken mit einem Namen.
    $speicher = new LokalerZuweisungsspeicher(fn (int $konto): ?string => 'department@peppermint-digital.de');

    $zuweisung = $speicher->zuweisen(1, '<a@b.test>', 'kette-1', probeAnna(), probeIch());

    expect($zuweisung->konto)->toBe(1)
        ->and($zuweisung->postfach)->toBe('department@peppermint-digital.de')
        ->and($zuweisung->an->nummer)->toBe(3)
        ->and($zuweisung->an->adresse)->toBe('anna@example.test')
        ->and($zuweisung->von->nummer)->toBe(7)
        ->and($speicher->fuer(1, '<a@b.test>', 'kette-1')->an->nummer)->toBe(3)
        ->and($speicher->offene(1)->erreichbar)->toBeTrue()
        ->and($speicher->offene(1)->zuweisungen)->toHaveCount(1);
});

it('haengt die Zuweisung am selben Faden wie ein Werkzeug am Vorgang', function () {
    // Liefen die beiden auseinander, haenge die Zuweisung an der einen Kette
    // und das Ticket an der anderen — ohne dass etwas bricht.
    $mit = new Zuweisung(konto: 1, postfach: 'a@b.test', nachricht: '<m@b.test>', kette: 'kette-1', an: probeAnna());
    $ohne = new Zuweisung(konto: 1, postfach: 'a@b.test', nachricht: '<m@b.test>', kette: null, an: probeAnna());

    expect($mit->anker())->toBe('kette-1')
        ->and($ohne->anker())->toBe('<m@b.test>')
        ->and($mit->anker())->toBe(
            (new Vorgang('a@b.test', 'kette-1', '<m@b.test>'))->anker()
        );
});

it('schickt keine Benutzer-Nummer in die Mitte', function () {
    // Der Fehler, der im ersten Entwurf dieses Umbaus drinsteckte: Nutzer 5 im
    // CRM und Nutzer 5 im Manager sind zwei verschiedene Menschen. Eine Nummer
    // hier haengt die Zustaendigkeit beim naechsten Systemwechsel an jemand
    // anderen — ohne Fehler, nur mit dem falschen Namen am Abzeichen.
    $draht = (new Zuweisung(
        konto: 42,
        postfach: 'a@b.test',
        nachricht: '<m@b.test>',
        kette: null,
        an: probeAnna(),
        von: probeIch(),
    ))->toArray();

    expect($draht)->not->toHaveKey('assigned_to_user_id')
        ->and($draht)->not->toHaveKey('assigned_by_user_id')
        ->and($draht['assigned_to'])->toBe('anna@example.test')
        ->and($draht['assigned_by'])->toBe('ich@example.test')
        // Und keine Zahl irgendwo in der Nutzlast.
        ->and(array_filter($draht, fn ($wert): bool => is_int($wert)))->toBe([]);
});

it('zeigt eine Zustaendigkeit auch, wenn es die Person hier nicht gibt', function () use ($personen) {
    // Eine Mitte kann fuer jemanden zustaendig sein, der dieses Postfach hier
    // nicht sehen darf. Die Zeile zu verwerfen hiesse: Die Mail sieht frei
    // aus, obwohl jemand an ihr arbeitet.
    app()->instance(Zuweisungsspeicher::class, brainSpeicher(['ok' => true, 'data' => ['assignments' => [
        ['message_id' => '<a@b.test>', 'assigned_to' => 'fremde@anderes-system.test'],
    ]]]));

    $antwort = (new TestControllerFuerSpeicher($personen))->assignments(1)->getData(true);

    expect($antwort['assignments'])->toHaveCount(1)
        ->and($antwort['assignments'][0]['user_id'])->toBeNull()
        ->and($antwort['assignments'][0]['email'])->toBe('fremde@anderes-system.test')
        // Beschriftet mit der Adresse, nicht leer: Ein Abzeichen ohne Text
        // kann niemand deuten.
        ->and($antwort['assignments'][0]['name'])->toBe('fremde@anderes-system.test');
});

it('verweigert eine Zuweisung an eine Person ohne Adresse', function () {
    // Eine Absage, keine stille Nichtzuweisung: Ohne Adresse existiert die
    // Zustaendigkeit im naechsten System nicht, und niemand erfaehrt es.
    app()->instance(Zuweisungsspeicher::class, brainSpeicher(['ok' => true, 'data' => ['assignment' => [
        'message_id' => '<a@b.test>', 'assigned_to' => 'anna@example.test',
    ]]]));

    $antwort = (new TestControllerFuerSpeicher([['id' => 9, 'name' => 'Ohne Adresse', 'email' => '']]))
        ->assign(speicherAnfrage(['message_id' => '<a@b.test>', 'assigned_to_user_id' => 9]), 1);

    expect($antwort->getStatusCode())->toBe(422)
        ->and($antwort->getData(true)['message'])->toContain('E-Mail-Adresse');
});

it('schickt die oertliche Postfach-Nummer nicht in die Mitte', function () {
    // Sie hat dort keine Bedeutung und waere die Falle, vor der die
    // Vertragsdoku warnt.
    $zuweisung = new Zuweisung(konto: 42, postfach: 'a@b.test', nachricht: '<m@b.test>', kette: null, an: probeAnna());

    expect($zuweisung->toArray())->not->toHaveKey('konto')
        ->and($zuweisung->toArray())->not->toHaveKey('account_id')
        ->and($zuweisung->toArray()['mailbox'])->toBe('a@b.test');
});

it('haelt je Kette EINE Zeile, auch wenn dieselbe Zuweisung zweimal kommt', function () {
    /*
     * Die Zusage, auf der der UMZUG steht.
     *
     * `mailbox:zustand-umziehen` ist ausdruecklich wiederholbar — ein Umzug,
     * den man nach einem Abbruch nicht noch einmal laufen lassen darf, wird
     * zum Ratespiel. Sein Test dafuer („kann zweimal laufen") prueft aber nur,
     * dass das KOMMANDO zweimal durchlaeuft; dass die MITTE dabei keine
     * zweite Zeile anlegt, war bis zum 20.10.2026 nirgends geprueft — nur in
     * einem Kommentar behauptet.
     *
     * Gefunden bei der Selbstdurchsicht vor dem Ausrollen. Eine Behauptung
     * ueber fremdes Verhalten, auf die ein Schreibvorgang gegen echte Daten
     * baut, gehoert belegt.
     */
    $speicher = new LokalerZuweisungsspeicher(fn (int $konto): ?string => 'department@peppermint-digital.de');

    $speicher->zuweisen(1, '<a@b.test>', 'kette-1', probeAnna(), probeIch());
    $speicher->zuweisen(1, '<a@b.test>', 'kette-1', probeAnna(), probeIch());

    expect($speicher->offene(1)->zuweisungen)->toHaveCount(1);
});

it('schreibt eine neue Person auf dieselbe Kette, statt eine zweite Zeile zu legen', function () {
    /*
     * Die andere Haelfte: Ein zweiter Lauf soll nicht nur nichts verdoppeln,
     * er soll auch UEBERSCHREIBEN koennen. Zwei Zeilen zur selben Kette mit
     * verschiedenen Personen waeren zwei Antworten auf „wer kuemmert sich" —
     * und welche die Oberflaeche zeigt, entschiede die Reihenfolge.
     */
    $speicher = new LokalerZuweisungsspeicher(fn (int $konto): ?string => 'department@peppermint-digital.de');

    $speicher->zuweisen(1, '<a@b.test>', 'kette-1', probeAnna(), probeIch());
    $speicher->zuweisen(1, '<a@b.test>', 'kette-1', probeIch(), probeAnna());

    $offene = $speicher->offene(1)->zuweisungen;

    expect($offene)->toHaveCount(1)
        ->and($offene[0]->an->nummer)->toBe(7);
});

it('haelt OHNE Kette je Nachricht eine Zeile', function () {
    // Ohne Kettenkennung ist die Nachricht der Schluessel. Auch dort darf ein
    // zweiter Lauf nichts verdoppeln.
    $speicher = new LokalerZuweisungsspeicher(fn (int $konto): ?string => 'department@peppermint-digital.de');

    $speicher->zuweisen(1, '<a@b.test>', null, probeAnna(), probeIch());
    $speicher->zuweisen(1, '<a@b.test>', null, probeAnna(), probeIch());

    expect($speicher->offene(1)->zuweisungen)->toHaveCount(1);
});

it('haelt zwei VERSCHIEDENE Ketten auseinander', function () {
    // Die Gegenprobe. Ein Speicher, der alles auf eine Zeile zusammenzieht,
    // besteht die drei Tests darueber ebenfalls.
    $speicher = new LokalerZuweisungsspeicher(fn (int $konto): ?string => 'department@peppermint-digital.de');

    $speicher->zuweisen(1, '<a@b.test>', 'kette-1', probeAnna(), probeIch());
    $speicher->zuweisen(1, '<c@d.test>', 'kette-2', probeAnna(), probeIch());

    expect($speicher->offene(1)->zuweisungen)->toHaveCount(2);
});
