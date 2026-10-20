<?php

use Illuminate\Database\Eloquent\Model;
use Peppermint\Mailbox\Werkzeuge\Ergebnis;
use Peppermint\Mailbox\Werkzeuge\FremdeWerkzeuge;
use Peppermint\Mailbox\Werkzeuge\GesamtWerkzeuge;
use Peppermint\Mailbox\Werkzeuge\Vorgang;
use Peppermint\Mailbox\Werkzeuge\Vorgangswerkzeug;
use Peppermint\Mailbox\Werkzeuge\Werkzeug;
use Peppermint\Mailbox\Werkzeuge\Werkzeugquelle;
use Peppermint\Mailbox\Werkzeuge\Werkzeugverzeichnis;

/**
 * Die zusammengelegte Werkzeugleiste (19.10.2026).
 *
 * Geprueft wird das, was still falsch sein kann — also genau die Faelle, in
 * denen die Leiste vollstaendig AUSSIEHT und nicht vollstaendig IST:
 *
 * - Traegt ein fremdes Verb die Herkunft, die der FRAGENDE vergibt? Ohne sie
 *   ist es nicht zustellbar, und „Aufgabe anhaengen" kann nicht mehr
 *   unterschieden werden vom gleichnamigen Verb des anderen Systems.
 * - Nimmt ein gescheitertes Werkzeug die anderen mit? Dann sieht ein Ausfall
 *   aus wie „hier gibt es nichts zu tun".
 * - Wird ein zugestellter, aber abgelehnter Auftrag als Erfolg gemeldet? Das
 *   ist der Fall, in dem der Nutzer einen gruenen Haken sieht und im
 *   Zielsystem nichts entstanden ist.
 *
 * `Auspacken::liste()` ist hier NICHT geprueft: Es braucht `Huelle` aus der
 * Bridge, und die ist ein `suggest` — im Testbaum des Pakets gibt es sie
 * nicht. `ergebnis()` kommt ohne sie aus und ist die Haelfte, in der die
 * teuren Fehler stecken.
 */
class ProbeWerkzeug implements Vorgangswerkzeug
{
    public ?Vorgang $gesehen = null;

    public array $empfangen = [];

    public function __construct(
        private readonly string $schluessel,
        private readonly string $wort = 'Probe',
        private readonly bool $anwendbar = true,
        private readonly ?array $zustand = null,
        private readonly ?string $gruppe = null,
        private readonly int $reihenfolge = 100,
        private readonly bool $wirft = false,
    ) {}

    public function werkzeug(): Werkzeug
    {
        return new Werkzeug(
            schluessel: $this->schluessel,
            wort: $this->wort,
            reihenfolge: $this->reihenfolge,
            gruppe: $this->gruppe,
        );
    }

    public function anwendbar(Vorgang $vorgang, Model $person): bool
    {
        if ($this->wirft) {
            throw new RuntimeException('Das Ticketsystem antwortet nicht.');
        }

        $this->gesehen = $vorgang;

        return $this->anwendbar;
    }

    public function zustand(Vorgang $vorgang, Model $person): ?array
    {
        return $this->zustand;
    }

    public function verweis(Vorgang $vorgang, Model $person): ?string
    {
        return null;
    }

    public function ausfuehren(Vorgang $vorgang, array $daten, Model $person): Ergebnis
    {
        $this->empfangen = $daten;

        return Ergebnis::gelungen('Beleg #4711 angelegt', verweis: '/belege/4711');
    }
}

/** Eine Quelle, die vorgibt, was die anderen Systeme antworten. */
class ProbeQuelle implements Werkzeugquelle
{
    public array $gefragt = [];

    public array $ausgeloest = [];

    public function __construct(
        private readonly array $anbieter = [],
        private readonly array $antworten = [],
        private readonly array $stumm = [],
        private readonly array $ergebnisse = [],
    ) {}

    public function anbieter(): array
    {
        return $this->anbieter;
    }

    public function holen(string $slug, Vorgang $vorgang, string $adresse): array
    {
        $this->gefragt[] = $slug;

        if (in_array($slug, $this->stumm, true)) {
            throw new RuntimeException('Zeitueberschreitung.');
        }

        return $this->antworten[$slug] ?? ['tools' => [], 'failed' => []];
    }

    public function ausloesen(string $slug, string $schluessel, Vorgang $vorgang, array $daten, string $adresse): array
    {
        $this->ausgeloest[] = ['slug' => $slug, 'key' => $schluessel, 'daten' => $daten];

        return $this->ergebnisse[$slug] ?? ['ok' => true, 'message' => 'Erledigt.'];
    }
}

function probePerson(?string $adresse = 'henneberg@peppermint-digital.de'): Model
{
    $person = new class extends Model
    {
        protected $guarded = [];
    };

    $person->setAttribute('email', $adresse);

    return $person;
}

function probeVorgang(): Vorgang
{
    return new Vorgang(
        postfach: 'department@peppermint-digital.de',
        kette: 'kette-1',
        nachricht: '<a@example.test>',
        betreff: 'Anfrage Bauvorhaben Meier',
    );
}

function gesamt(Werkzeugverzeichnis $eigene, Werkzeugquelle $quelle): GesamtWerkzeuge
{
    return new GesamtWerkzeuge($eigene, new FremdeWerkzeuge($quelle));
}

function verzeichnis(array $werkzeuge): Werkzeugverzeichnis
{
    $v = new Werkzeugverzeichnis(app());

    return $v->anmeldenViele($werkzeuge);
}

it('gibt eigenen Verben keine Herkunft und fremden die des fragenden Systems', function () {
    // Duerfte ein System sich selbst benennen, stuenden in vier Oberflaechen
    // vier verschiedene Woerter — und ein Verb liesse sich nicht zustellen.
    // Deshalb setzt der FRAGENDE die Herkunft, aus seinem Register.
    $quelle = new ProbeQuelle(
        anbieter: [['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM']],
        antworten: ['peppermint-crm' => [
            // Das befragte System schickt ein `system` mit, das NICHT gelten
            // darf — sonst traegt es sich selbst ein.
            'tools' => [['key' => 'kontakt', 'label' => 'Kontakt zuordnen', 'system' => 'ich-selbst']],
            'failed' => [],
        ]],
    );

    $leiste = gesamt(verzeichnis([new ProbeWerkzeug('beleg', 'Als Eingangsrechnung')]), $quelle)
        ->fuer(probeVorgang(), probePerson());

    $nachSchluessel = collect($leiste['tools'])->keyBy('key');

    expect($nachSchluessel['beleg']['system'])->toBeNull()
        ->and($nachSchluessel['kontakt']['system'])->toBe('crm')
        ->and($nachSchluessel['kontakt']['system_label'])->toBe('CRM');
});

it('laesst ein gescheitertes eigenes Werkzeug die Leiste nicht mitnehmen', function () {
    // Ein Ticketsystem, das gerade wandert, darf nicht dazu fuehren, dass
    // auch „Aufgabe anhaengen" verschwindet.
    $leiste = gesamt(
        verzeichnis([
            new ProbeWerkzeug('kaputt', 'Ticket erstellen', wirft: true),
            new ProbeWerkzeug('aufgabe', 'Aufgabe anhaengen'),
        ]),
        new ProbeQuelle,
    )->fuer(probeVorgang(), probePerson());

    expect($leiste['tools'])->toHaveCount(1)
        ->and($leiste['tools'][0]['key'])->toBe('aufgabe')
        // Und es steht da. Eine still verkuerzte Leiste sieht aus wie „hier
        // ist nichts zu tun", und dann sucht man im falschen System.
        ->and($leiste['failed'])->toContain('Ticket erstellen');
});

it('nennt ein stummes System, statt seine Verben lautlos wegzulassen', function () {
    $quelle = new ProbeQuelle(
        anbieter: [
            ['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM'],
            ['slug' => 'peppermint-verwaltung', 'key' => 'verwaltung', 'label' => 'Verwaltung'],
        ],
        antworten: ['peppermint-verwaltung' => [
            'tools' => [['key' => 'beleg', 'label' => 'Als Eingangsrechnung']],
            'failed' => ['Ticket'],
        ]],
        stumm: ['peppermint-crm'],
    );

    $leiste = gesamt(verzeichnis([]), $quelle)->fuer(probeVorgang(), probePerson());

    expect($leiste['tools'])->toHaveCount(1)
        ->and($leiste['unreachable'])->toBe(['CRM'])
        // Mit dem Systemnamen davor — sonst steht in der Ansicht „Ticket" und
        // niemand weiss, in welchem der vier Systeme man nachsehen soll.
        ->and($leiste['failed'])->toBe(['Verwaltung: Ticket']);
});

it('behaelt bei zwei gleichen Schluesseln eines und wirft das andere nicht still weg', function () {
    // Das Ausloesen waere ab hier Zufall. Geprueft wird, dass nur EINES in
    // der Leiste steht — die Warnung steht im Log.
    $leiste = gesamt(
        verzeichnis([
            new ProbeWerkzeug('aufgabe', 'Aufgabe anhaengen'),
            new ProbeWerkzeug('aufgabe', 'Aufgabe anlegen'),
        ]),
        new ProbeQuelle,
    )->fuer(probeVorgang(), probePerson());

    expect($leiste['tools'])->toHaveCount(1)
        ->and($leiste['tools'][0]['label'])->toBe('Aufgabe anhaengen');
});

it('schickt den Zustand in der Drahtform, damit er die Systemgrenze ueberlebt', function () {
    // Innen heisst es `wort`/`verweis`, auf der Leitung `label`/`url`. Wer
    // die inneren Namen hinausschickt, verliert den Zustand lautlos — und
    // die Leiste bietet ein Verb an, das drueben schon ausgefuehrt ist.
    $leiste = gesamt(
        verzeichnis([new ProbeWerkzeug('beleg', 'Als Eingangsrechnung', zustand: ['wort' => 'Beleg #4711', 'verweis' => '/belege/4711'])]),
        new ProbeQuelle,
    )->fuer(probeVorgang(), probePerson());

    expect($leiste['tools'][0]['state'])->toBe(['label' => 'Beleg #4711', 'url' => '/belege/4711']);

    // Und die Gegenprobe: dieselbe Form kommt drueben wieder an.
    $zurueck = Werkzeug::aus($leiste['tools'][0]);

    expect($zurueck->zustand)->toBe(['wort' => 'Beleg #4711', 'verweis' => '/belege/4711']);
});

it('sortiert nach Gruppe und Reihenfolge, nicht nach System', function () {
    // Sonst zerfaellt die Leiste wieder in die Produktkaestchen, die sie
    // gerade aufloest.
    $quelle = new ProbeQuelle(
        anbieter: [['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM']],
        antworten: ['peppermint-crm' => [
            'tools' => [['key' => 'kontakt', 'label' => 'Kontakt', 'group' => 'zuordnen', 'order' => 10]],
            'failed' => [],
        ]],
    );

    $leiste = gesamt(
        verzeichnis([
            new ProbeWerkzeug('beleg', 'Beleg', gruppe: 'verbuchen', reihenfolge: 10),
            new ProbeWerkzeug('aufgabe', 'Aufgabe', gruppe: 'zuordnen', reihenfolge: 20),
        ]),
        $quelle,
    )->fuer(probeVorgang(), probePerson());

    // „verbuchen" vor „zuordnen", und innerhalb von „zuordnen" steht das
    // fremde Verb (10) vor dem eigenen (20).
    expect(collect($leiste['tools'])->pluck('key')->all())->toBe(['beleg', 'kontakt', 'aufgabe']);
});

it('loest ein eigenes Werkzeug ohne Systemkuerzel aus', function () {
    $werkzeug = new ProbeWerkzeug('beleg', 'Als Eingangsrechnung');
    $quelle = new ProbeQuelle;

    $ergebnis = gesamt(verzeichnis([$werkzeug]), $quelle)
        ->ausfuehren(null, 'beleg', probeVorgang(), ['datum' => '2026-10-19'], probePerson());

    expect($ergebnis->gelungen)->toBeTrue()
        ->and($werkzeug->empfangen)->toBe(['datum' => '2026-10-19'])
        // Und es ging nirgendwo nach draussen.
        ->and($quelle->ausgeloest)->toBe([]);
});

it('loest genau das genannte System aus und nicht irgendeines, das das Verb auch kann', function () {
    // „Aufgabe anhaengen" gibt es in zwei Systemen. Welches gemeint war, hat
    // der Nutzer beim Klick entschieden — der Gateway darf hier nicht raten.
    $quelle = new ProbeQuelle(anbieter: [
        ['slug' => 'peppermint-manager', 'key' => 'manager', 'label' => 'Manager'],
        ['slug' => 'peppermint-verwaltung', 'key' => 'verwaltung', 'label' => 'Verwaltung'],
    ]);

    gesamt(verzeichnis([]), $quelle)
        ->ausfuehren('verwaltung', 'aufgabe', probeVorgang(), [], probePerson());

    expect($quelle->ausgeloest)->toHaveCount(1)
        ->and($quelle->ausgeloest[0]['slug'])->toBe('peppermint-verwaltung');
});

it('verweigert ein Systemkuerzel, das nicht mehr im Register steht', function () {
    // Eine alte, offene Registerkarte schickt ein Kuerzel zurueck, das dieser
    // Nutzer nicht mehr benutzen darf. Als Erfolg zu antworten waere die
    // schlimmere Luege.
    $quelle = new ProbeQuelle(anbieter: [['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM']]);

    $ergebnis = gesamt(verzeichnis([]), $quelle)
        ->ausfuehren('verwaltung', 'beleg', probeVorgang(), [], probePerson());

    expect($ergebnis->gelungen)->toBeFalse()
        ->and($quelle->ausgeloest)->toBe([]);
});

it('fragt ohne Postfach oder Nachricht kein einziges System', function () {
    $quelle = new ProbeQuelle(anbieter: [['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM']]);

    $leiste = gesamt(verzeichnis([new ProbeWerkzeug('beleg')]), $quelle)
        ->fuer(new Vorgang(postfach: '', kette: null, nachricht: ''), probePerson());

    expect($leiste['tools'])->toBe([])
        ->and($quelle->gefragt)->toBe([]);
});

it('behaelt ohne Adresse am Benutzer die eigenen Verben vollstaendig', function () {
    // Ohne Adresse kann kein anderes System den Menschen finden. Das kostet
    // die fremden Verben — nicht die Leiste.
    $quelle = new ProbeQuelle(anbieter: [['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM']]);

    $leiste = gesamt(verzeichnis([new ProbeWerkzeug('beleg')]), $quelle)
        ->fuer(probeVorgang(), probePerson(null));

    expect($leiste['tools'])->toHaveCount(1)
        ->and($quelle->gefragt)->toBe([]);
});

it('loest ohne Adresse am Benutzer nichts in einem fremden System aus', function () {
    // Ein Vorgang ohne handelnde Person waere ein Vorgang, den niemand
    // verantwortet.
    $quelle = new ProbeQuelle(anbieter: [['slug' => 'peppermint-crm', 'key' => 'crm', 'label' => 'CRM']]);

    $ergebnis = gesamt(verzeichnis([]), $quelle)
        ->ausfuehren('crm', 'kontakt', probeVorgang(), [], probePerson(null));

    expect($ergebnis->gelungen)->toBeFalse()
        ->and($quelle->ausgeloest)->toBe([]);
});

it('meldet einen zugestellten, aber drueben abgelehnten Auftrag als Fehlschlag', function () {
    // Zwei Ebenen: Der Gateway sagt, ob die ZUSTELLUNG klappte, das Werkzeug,
    // ob der VORGANG klappte. Wer nur die aeussere Schale liest, zeigt einen
    // gruenen Haken fuer etwas, das nicht passiert ist.
    $quelle = new ProbeQuelle(
        anbieter: [['slug' => 'peppermint-verwaltung', 'key' => 'verwaltung', 'label' => 'Verwaltung']],
        ergebnisse: ['peppermint-verwaltung' => ['ok' => false, 'message' => 'Dem Beleg fehlt ein Datum.']],
    );

    $ergebnis = gesamt(verzeichnis([]), $quelle)
        ->ausfuehren('verwaltung', 'beleg', probeVorgang(), [], probePerson());

    expect($ergebnis->gelungen)->toBeFalse()
        ->and($ergebnis->wort)->toBe('Dem Beleg fehlt ein Datum.');
});

it('nimmt den Zustand aus einem fremden Ergebnis mit', function () {
    // Damit aus dem Knopf der Verweis wird und der zweite Klick nicht ein
    // zweites Ticket anlegt.
    $quelle = new ProbeQuelle(
        anbieter: [['slug' => 'peppermint-verwaltung', 'key' => 'verwaltung', 'label' => 'Verwaltung']],
        ergebnisse: ['peppermint-verwaltung' => [
            'ok' => true,
            'message' => 'Beleg #4711 angelegt',
            'state' => ['label' => 'Beleg #4711', 'url' => '/belege/4711'],
        ]],
    );

    $ergebnis = gesamt(verzeichnis([]), $quelle)
        ->ausfuehren('verwaltung', 'beleg', probeVorgang(), [], probePerson());

    expect($ergebnis->gelungen)->toBeTrue()
        ->and($ergebnis->zustand)->toBe(['wort' => 'Beleg #4711', 'verweis' => '/belege/4711']);
});

it('meldet ein unbekanntes eigenes Verb, statt stillzuhalten', function () {
    // Ein unbekannter Schluessel heisst, dass die Oberflaeche ein Verb
    // anbietet, das es hier nicht gibt — meist ein System, das einen Schritt
    // weiter ausgerollt ist.
    $ergebnis = gesamt(verzeichnis([]), new ProbeQuelle)
        ->ausfuehren(null, 'gibtsnicht', probeVorgang(), [], probePerson());

    expect($ergebnis->gelungen)->toBeFalse()
        ->and($ergebnis->wort)->toContain('gibt es in diesem System nicht');
});

it('macht aus einem Werkzeug ohne Schluessel oder Wort keinen toten Knopf', function () {
    expect(Werkzeug::aus(['label' => 'Ohne Schluessel']))->toBeNull()
        ->and(Werkzeug::aus(['key' => 'ohne-wort']))->toBeNull()
        ->and(Werkzeug::aus(['key' => 'beleg', 'label' => 'Beleg']))->not->toBeNull();
});

it('nennt Kette und Nachricht als Anker an genau einer Stelle', function () {
    // „Kette, sonst Nachricht" stand im Paket an drei Stellen. Genau solche
    // Wiederholungen erzeugen einen Zustand, den keine Seite kennt.
    expect((new Vorgang('a@b.test', 'kette-1', '<m@b.test>'))->anker())->toBe('kette-1')
        ->and((new Vorgang('a@b.test', null, '<m@b.test>'))->anker())->toBe('<m@b.test>');
});

it('schreibt die Postfachadresse klein, damit sie ueber Systemgrenzen trifft', function () {
    // Identifiziert wird ueber die Adresse. Eine Gross-/Kleinschreibung, die
    // nur hier so ist, trifft drueben nichts — und es gibt keinen Fehler.
    expect(Vorgang::aus(['mailbox' => ' Department@Peppermint-Digital.DE ', 'message_id' => '<a@b.test>'])->postfach)
        ->toBe('department@peppermint-digital.de');
});

/** Ein Verb, das nur hinfuehrt — fuer alles, was drueben einen Auswaehler braucht. */
class VerweisWerkzeug implements Vorgangswerkzeug
{
    public bool $ausgefuehrt = false;

    public function werkzeug(): Werkzeug
    {
        return new Werkzeug(
            schluessel: 'kontakt',
            wort: 'Im CRM zuordnen',
        );
    }

    public function verweis(Vorgang $vorgang, Model $person): ?string
    {
        // Die Adresse haengt am Vorgang — genau deshalb steht sie hier und
        // nicht in `werkzeug()`.
        return 'https://crm.test/mailbox?thread='.urlencode($vorgang->anker());
    }

    public function anwendbar(Vorgang $vorgang, Model $person): bool
    {
        return true;
    }

    public function zustand(Vorgang $vorgang, Model $person): ?array
    {
        return null;
    }

    public function ausfuehren(Vorgang $vorgang, array $daten, Model $person): Ergebnis
    {
        $this->ausgefuehrt = true;

        return Ergebnis::gelungen('haette nicht passieren duerfen');
    }
}

it('gibt ein Verweis-Verb mit seiner Adresse heraus', function () {
    // „Kontakt zuordnen" braucht einen Auswaehler mit Suche. Den kann ein
    // fremdes System nicht zeichnen — also fuehrt das Verb hin.
    $leiste = gesamt(verzeichnis([new VerweisWerkzeug]), new ProbeQuelle)
        ->fuer(probeVorgang(), probePerson());

    expect($leiste['tools'][0]['url'])->toBe('https://crm.test/mailbox?thread=kette-1');
});

it('loest ein Verweis-Verb NICHT aus, auch wenn es verlangt wird', function () {
    /*
     * Ein veralteter Stand drueben koennte es versuchen. `ausfuehren()` ist
     * bei so einem Verb meist nicht sinnvoll umgesetzt — es auf gut Glueck zu
     * rufen hiesse, eine Methode zu nehmen, die nie dafuer gedacht war.
     */
    $werkzeug = new VerweisWerkzeug;

    $ergebnis = gesamt(verzeichnis([$werkzeug]), new ProbeQuelle)
        ->ausfuehren(null, 'kontakt', probeVorgang(), [], probePerson());

    expect($ergebnis->gelungen)->toBeFalse()
        ->and($werkzeug->ausgefuehrt)->toBeFalse();
});

it('traegt den Verweis des Verbs ueber die Systemgrenze', function () {
    // Und nicht zu verwechseln mit dem Verweis IM Zustand: Der eine fuehrt
    // zum Auswaehler, der andere zu der Sache, die es schon gibt.
    $leiste = gesamt(verzeichnis([new VerweisWerkzeug]), new ProbeQuelle)
        ->fuer(probeVorgang(), probePerson());

    $zurueck = Werkzeug::aus($leiste['tools'][0]);

    expect($zurueck->fuehrtHin())->toBeTrue()
        ->and($zurueck->verweis)->toBe('https://crm.test/mailbox?thread=kette-1');
});
