<?php

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Peppermint\Mailbox\Contracts\Zuweisbare;
use Peppermint\Mailbox\Contracts\Zuweisungsspeicher;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Werkzeuge\PersonZuordnen;
use Peppermint\Mailbox\Werkzeuge\Vorgang;
use Peppermint\Mailbox\Werkzeuge\Werkzeugverzeichnis;
use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Zuweisung;
use Peppermint\Mailbox\Zustand\Zuweisungsliste;

/**
 * „Zuständig" als Verb in der Werkzeugleiste (#8364).
 *
 * Das erste Werkzeug, das dem PAKET gehoert und nicht einem Produkt — und das
 * erste mit einem Eingabefeld. Beides hat eigene Fallen, und die stehen hier:
 *
 * - Ein paketeigenes Verb darf nicht an fremde Systeme herausgehen, sonst
 *   kommt es vierfach zurueck.
 * - Ein Formularwert ist eine Behauptung des Browsers, keine Erlaubnis.
 */
uses(RefreshDatabase::class);

class ZuordnenNutzer extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected $table = 'zuordnen_nutzer';

    protected $guarded = [];

    public $timestamps = false;
}

/** Eine Zuweisbaren-Liste, wie ein Produkt sie bindet. */
class FesteZuweisbare implements Zuweisbare
{
    /** @param list<array{id: int, name: string, email: string}> $personen */
    public function __construct(private readonly array $personen) {}

    public function fuer(int $konto): array
    {
        return $this->personen;
    }
}

/** Eine Mitte, die mitschreibt, WAS bei ihr ankommt. */
class ZuordnenMitte implements Zuweisungsspeicher
{
    /** @var list<array{an: string, name: ?string, von: ?string}> */
    public array $geschrieben = [];

    public function __construct(
        public bool $schreibbar = true,
        public ?Zuweisung $vorhanden = null,
        public bool $nimmtAn = true,
    ) {}

    public function schreibbar(): bool
    {
        return $this->schreibbar;
    }

    public function offene(int $konto): Zuweisungsliste
    {
        return Zuweisungsliste::mit([]);
    }

    public function fuer(int $konto, string $nachricht, ?string $kette): ?Zuweisung
    {
        return $this->vorhanden;
    }

    public function zuweisen(int $konto, string $nachricht, ?string $kette, Person $an, ?Person $von): ?Zuweisung
    {
        if (! $this->nimmtAn) {
            return null;
        }

        $this->geschrieben[] = ['an' => $an->adresse, 'name' => $an->name, 'von' => $von?->adresse];

        return new Zuweisung(
            konto: $konto,
            postfach: 'department@example.test',
            nachricht: $nachricht,
            kette: $kette,
            an: $an,
            von: $von,
        );
    }

    public function aufheben(int $konto, string $nachricht, ?string $kette): bool
    {
        return true;
    }
}

beforeEach(function () {
    Schema::create('zuordnen_nutzer', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
    });

    $this->anna = ZuordnenNutzer::create(['name' => 'Anna Meier', 'email' => 'anna@example.test']);

    MailAccount::create([
        'label' => 'Abteilung',
        'email' => 'department@example.test',
        'imap_host' => 'mail.example.test',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'username' => 'department',
        'password' => 'geheim',
        'is_active' => true,
    ]);

    $this->mitte = new ZuordnenMitte;
    app()->instance(Zuweisungsspeicher::class, $this->mitte);

    app()->instance(Zuweisbare::class, new FesteZuweisbare([
        ['id' => 1, 'name' => 'Anna Meier', 'email' => 'anna@example.test'],
        ['id' => 2, 'name' => 'Bodo Brecht', 'email' => 'bodo@example.test'],
    ]));

    $this->vorgang = new Vorgang(
        postfach: 'department@example.test',
        kette: 'kette-1',
        nachricht: '<a@example.test>',
    );
});

it('bietet die zuweisbaren Personen als Auswahl an — mit ADRESSEN als Werten', function () {
    /*
     * Die Adresse und nicht die Nummer. Nutzer 2 ist im CRM und im Manager ein
     * anderer Mensch, und die Zustaendigkeit liegt in der Mitte: Eine Nummer
     * hier haengte die Arbeit beim naechsten Systemwechsel an jemand anderen.
     */
    $felder = (new PersonZuordnen)->felder($this->vorgang, $this->anna);

    expect($felder)->toHaveCount(1)
        ->and($felder[0]['name'])->toBe('person')
        ->and($felder[0]['art'])->toBe('select')
        ->and($felder[0]['pflicht'])->toBeTrue()
        ->and(array_column($felder[0]['auswahl'], 'wert'))->toBe(['anna@example.test', 'bodo@example.test']);
});

it('laesst niemanden zuweisen, der nicht in der Liste steht', function () {
    /*
     * Der Kern dieses Tests: Der Browser schickt, was er will. Zulaessig ist
     * nur, was DIESES Postfach hergibt — zweimal hat dieses Projekt fremde
     * Posteingaenge geoeffnet, weil eine Pruefung uebernommen statt gestellt
     * wurde (Memories #4003, #4009).
     */
    $ergebnis = (new PersonZuordnen)->ausfuehren(
        $this->vorgang,
        ['person' => 'fremde@woanders.test'],
        $this->anna,
    );

    expect($ergebnis->gelungen)->toBeFalse()
        ->and($this->mitte->geschrieben)->toBe([]);
});

it('weist zu und nennt die handelnde Person als Zuweisende', function () {
    $ergebnis = (new PersonZuordnen)->ausfuehren(
        $this->vorgang,
        ['person' => 'bodo@example.test'],
        $this->anna,
    );

    expect($ergebnis->gelungen)->toBeTrue()
        ->and($this->mitte->geschrieben)->toBe([[
            'an' => 'bodo@example.test',
            'name' => 'Bodo Brecht',
            // Anna hat gehandelt, also hat Anna zugewiesen. Hier den
            // Empfaenger einzutragen hiesse, eine Selbstzuweisung zu behaupten.
            'von' => 'anna@example.test',
        ]]);
});

it('erfindet keinen Erfolg, wenn die Mitte nicht annimmt', function () {
    $this->mitte->nimmtAn = false;

    $ergebnis = (new PersonZuordnen)->ausfuehren(
        $this->vorgang,
        ['person' => 'bodo@example.test'],
        $this->anna,
    );

    expect($ergebnis->gelungen)->toBeFalse();
});

it('bietet sich NICHT an, wenn die Mitte gar nichts annimmt', function () {
    // Ein Knopf, der sicher scheitert, ist schlechter als keiner.
    $this->mitte->schreibbar = false;

    expect((new PersonZuordnen)->anwendbar($this->vorgang, $this->anna))->toBeFalse();
});

it('bietet sich NICHT an, wenn niemand zuweisbar ist', function () {
    // Eine leere Auswahl ist kein Formular.
    app()->instance(Zuweisbare::class, new FesteZuweisbare([]));

    expect((new PersonZuordnen)->anwendbar($this->vorgang, $this->anna))->toBeFalse();
});

it('laesst Personen ohne Adresse aus der Auswahl', function () {
    /*
     * Nicht verstecken, sondern weglassen — und der Unterschied ist wichtig:
     * Ein Eintrag in der Auswahl, der beim Klick abgelehnt wird, ist ein Knopf,
     * der zuverlaessig scheitert. Die Zahl der Personen ohne Adresse zeigt die
     * Zuweisungs-Oberflaeche an anderer Stelle an (`without_email`), damit eine
     * fehlende Pflege auffaellt.
     */
    app()->instance(Zuweisbare::class, new FesteZuweisbare([
        ['id' => 1, 'name' => 'Anna Meier', 'email' => 'anna@example.test'],
        ['id' => 3, 'name' => 'Ohne Adresse', 'email' => ''],
    ]));

    $felder = (new PersonZuordnen)->felder($this->vorgang, $this->anna);

    expect(array_column($felder[0]['auswahl'], 'wert'))->toBe(['anna@example.test']);
});

it('sagt, wer gerade zustaendig ist — sonst waere der Knopf eine Frage ohne Antwort', function () {
    $this->mitte->vorhanden = new Zuweisung(
        konto: 1,
        postfach: 'department@example.test',
        nachricht: '<a@example.test>',
        kette: 'kette-1',
        an: new Person(adresse: 'bodo@example.test', name: 'Bodo Brecht'),
        von: null,
    );

    $zustand = (new PersonZuordnen)->zustand($this->vorgang, $this->anna);

    expect($zustand['wort'])->toBe('Zuständig: Bodo Brecht')
        // Kein Verweis: Die Zustaendigkeit hat keine eigene Seite. Eine
        // Adresse hierhin zu erfinden fuehrte im Kreis.
        ->and($zustand['verweis'])->toBeNull();
});

it('nimmt die Woerter des Produkts, wenn es welche uebergibt', function () {
    // Das Paket BRINGT eine Sprache mit, es imponiert keine.
    $werkzeug = new PersonZuordnen(wort: 'Bearbeiter', zustandMuster: 'Macht: :name');

    $this->mitte->vorhanden = new Zuweisung(
        konto: 1,
        postfach: 'department@example.test',
        nachricht: '<a@example.test>',
        kette: 'kette-1',
        an: new Person(adresse: 'bodo@example.test', name: 'Bodo Brecht'),
        von: null,
    );

    expect($werkzeug->werkzeug()->wort)->toBe('Bearbeiter')
        ->and($werkzeug->zustand($this->vorgang, $this->anna)['wort'])->toBe('Macht: Bodo Brecht');
});

it('geht NICHT an fremde Systeme heraus — sonst kaeme es vierfach', function () {
    /*
     * Der Grund fuer den Marker `NurHier`. Jedes der vier Systeme meldet
     * dieses Verb selbst an. Ohne die Ausnahme holt jedes es dreimal von den
     * Nachbarn zurueck: vier gleich aussehende Knoepfe mit verschiedenen
     * Abzeichen, und drei davon fuehren ueber zwei Netzwerkspruenge zum
     * selben Ergebnis wie der erste.
     *
     * Genau die vier Kopien, die diese Leiste aufloesen sollte — eine Ebene
     * hoeher.
     */
    $verzeichnis = app(Werkzeugverzeichnis::class)->anmelden(new PersonZuordnen);

    $eigene = $verzeichnis->sammeln($this->vorgang, $this->anna);
    $fremde = $verzeichnis->sammeln($this->vorgang, $this->anna, fuerFremdeSysteme: true);

    expect(array_map(fn ($w) => $w->schluessel, $eigene['werkzeuge']))->toBe(['person'])
        ->and($fremde['werkzeuge'])->toBe([]);
});

it('haengt die Felder an das Werkzeug, wenn es welche fuehrt', function () {
    /*
     * Die Naht zwischen `LiefertFelder` und dem Verzeichnis. Ohne sie steht
     * das Feld in der Klasse und nie in der Antwort — und die Leiste loest
     * ein Verb ohne seine Pflichtangabe aus.
     */
    $gesammelt = app(Werkzeugverzeichnis::class)
        ->anmelden(new PersonZuordnen)
        ->sammeln($this->vorgang, $this->anna);

    $werkzeug = $gesammelt['werkzeuge'][0];

    expect($werkzeug->felder)->toHaveCount(1)
        ->and($werkzeug->felder[0]['name'])->toBe('person');

    // Und in der Drahtform mit den AEUSSEREN Namen — sonst liest das naechste
    // System `label`, wo `wort` steht, und das Feld hat keine Beschriftung.
    $draht = $werkzeug->toArray();

    expect($draht['fields'][0])->toMatchArray([
        'name' => 'person',
        'label' => 'Person',
        'type' => 'select',
        'required' => true,
    ])->and($draht['fields'][0]['options'][0])->toBe([
        'value' => 'anna@example.test',
        'label' => 'Anna Meier',
    ]);
});
