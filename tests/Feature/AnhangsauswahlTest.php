<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Werkzeuge\Ergebnis;
use Peppermint\Mailbox\Werkzeuge\LiefertAuswahl;
use Peppermint\Mailbox\Werkzeuge\LiefertFelder;
use Peppermint\Mailbox\Werkzeuge\Nachrichtenfund;
use Peppermint\Mailbox\Werkzeuge\OeffnetSeineMail;
use Peppermint\Mailbox\Werkzeuge\Vorgang;
use Peppermint\Mailbox\Werkzeuge\Vorgangswerkzeug;
use Peppermint\Mailbox\Werkzeuge\Werkzeug;
use Peppermint\Mailbox\Werkzeuge\Werkzeugverzeichnis;

/**
 * Die Feldart `anhang` beantwortet das PAKET — auch einem fremden System
 * (#9856).
 *
 * ## Der Fall
 *
 * {@see LiefertFelder} sichert in seiner Tabelle zu: Bei `art: anhang` liefert
 * **das Paket** die Auswahl, und das Produkt gibt KEINE Liste. Das galt nur
 * fuer die eigene Leiste — die hat die Mail offen. Fuer ein anderes System
 * landete dieselbe Feldart im Suchvertrag des Produkts, den es fuer diese Art
 * nicht haben soll.
 *
 * Gemessen auf Produktion am 12.11.2026 (Verwaltung, Bug #1446): dreimal eine
 * leere Auswahl, dreimal im Log
 *
 *     Mail-Werkzeug hat ein suchbares Feld, aber keinen Suchvertrag.
 *     {"tool":"App\Mailbox\BelegAusMail","key":"beleg","field":"anhang"}
 *
 * und zehn Belege, die deshalb liegen blieben. Der Fragende sah „diese Mail
 * hat keine Anhaenge", wo „ich kann dir die Liste nicht geben" richtig
 * gewesen waere.
 *
 * ## Was hier zugesichert wird
 *
 * 1. Die Namen kommen aus DIESER Mail, mit der Groesse als Hinweis.
 * 2. Der Wert ist der DATEINAME und nicht die Stelle — die stimmt nur,
 *    solange beide Seiten dieselbe Reihenfolge sehen.
 * 3. Ein Verb mit zwei Feldern bekommt zwei Wege: `anhang` vom Paket, das
 *    suchbare Feld weiter vom Produkt.
 * 4. Kann das Verb seine Mail nicht oeffnen, ist die Liste leer — aber LAUT
 *    im Log, denn dann ist sein Pflichtknopf von aussen nicht ausfuellbar.
 */
class AnhangsWerkzeug implements LiefertAuswahl, LiefertFelder, OeffnetSeineMail, Vorgangswerkzeug
{
    public array $auswahlGefragt = [];

    public int $nachrichtGefragt = 0;

    /** @param array<int, array<string, mixed>>|null $anhaenge null = Mail nicht zu finden */
    public function __construct(
        private readonly ?array $anhaenge = [],
        private readonly bool $anwendbar = true,
        private readonly bool $zweitesFeld = false,
    ) {}

    public function werkzeug(): Werkzeug
    {
        return new Werkzeug(schluessel: 'beleg', wort: 'Als Eingangsrechnung');
    }

    public function anwendbar(Vorgang $vorgang, Model $person): bool
    {
        return $this->anwendbar;
    }

    public function zustand(Vorgang $vorgang, Model $person): ?array
    {
        return null;
    }

    public function verweis(Vorgang $vorgang, Model $person): ?string
    {
        return null;
    }

    public function ausfuehren(Vorgang $vorgang, array $daten, Model $person): Ergebnis
    {
        return Ergebnis::gelungen('Verbucht.');
    }

    public function felder(Vorgang $vorgang, Model $person): array
    {
        $felder = [['name' => 'anhang', 'wort' => 'Anhang', 'art' => 'anhang', 'pflicht' => true]];

        if ($this->zweitesFeld) {
            $felder[] = ['name' => 'projekt', 'wort' => 'Projekt', 'art' => 'suche'];
        }

        return $felder;
    }

    public function auswahl(Vorgang $vorgang, Model $person, string $feld, string $suche): array
    {
        $this->auswahlGefragt[] = $feld;

        return [['wert' => 'p-1', 'wort' => 'Bauvorhaben Meier']];
    }

    public function nachricht(Vorgang $vorgang, Model $person): ?Nachrichtenfund
    {
        $this->nachrichtGefragt++;

        if ($this->anhaenge === null) {
            return null;
        }

        return new Nachrichtenfund(
            postfach: Mockery::mock(Mailbox::class),
            ordner: 'INBOX',
            kennung: 4711,
            nachricht: ['message_id' => '<a@example.test>', 'attachments' => $this->anhaenge],
        );
    }
}

/** Ein Verb MIT Anhangsfeld, das seine Mail NICHT oeffnen kann — der Mangel. */
class AnhangsWerkzeugOhneMail implements LiefertFelder, Vorgangswerkzeug
{
    public function werkzeug(): Werkzeug
    {
        return new Werkzeug(schluessel: 'beleg', wort: 'Als Eingangsrechnung');
    }

    public function anwendbar(Vorgang $vorgang, Model $person): bool
    {
        return true;
    }

    public function zustand(Vorgang $vorgang, Model $person): ?array
    {
        return null;
    }

    public function verweis(Vorgang $vorgang, Model $person): ?string
    {
        return null;
    }

    public function ausfuehren(Vorgang $vorgang, array $daten, Model $person): Ergebnis
    {
        return Ergebnis::gelungen('Verbucht.');
    }

    public function felder(Vorgang $vorgang, Model $person): array
    {
        return [['name' => 'anhang', 'wort' => 'Anhang', 'art' => 'anhang', 'pflicht' => true]];
    }
}

function anhangsPerson(): Model
{
    $person = new class extends Model
    {
        protected $guarded = [];
    };

    $person->setAttribute('email', 'henneberg@peppermint-digital.de');

    return $person;
}

function anhangsVorgang(): Vorgang
{
    return new Vorgang(
        postfach: 'department@peppermint-digital.de',
        kette: 'kette-1',
        nachricht: '<a@example.test>',
        betreff: 'Ihre Rechnung STEU426174784',
    );
}

function anhangsVerzeichnisMit(Vorgangswerkzeug $werkzeug): Werkzeugverzeichnis
{
    $verzeichnis = new Werkzeugverzeichnis(app());
    $verzeichnis->vergessen();
    $verzeichnis->anmelden($werkzeug);

    return $verzeichnis;
}

it('gibt die Anhaenge DIESER Mail heraus, mit der Groesse als Hinweis', function () {
    $werkzeug = new AnhangsWerkzeug([
        ['index' => 1, 'filename' => 'Rechnung STEU426174784.pdf', 'mime_type' => 'application/pdf', 'size' => 98443],
        ['index' => 2, 'filename' => 'Lieferschein.pdf', 'mime_type' => 'application/pdf', 'size' => 512],
    ]);

    $treffer = anhangsVerzeichnisMit($werkzeug)
        ->auswahl('beleg', 'anhang', '', anhangsVorgang(), anhangsPerson());

    expect($treffer)->toBe([
        ['wert' => 'Rechnung STEU426174784.pdf', 'wort' => 'Rechnung STEU426174784.pdf', 'hinweis' => '96 KB'],
        ['wert' => 'Lieferschein.pdf', 'wort' => 'Lieferschein.pdf', 'hinweis' => '512 B'],
    ]);

    /*
     * Der Wert ist der DATEINAME und nicht die Stelle (`index`). Die Stelle
     * stimmt nur, solange beide Seiten dieselbe Mail in derselben Reihenfolge
     * sehen — bei einer Mail, die ein anderes System geholt hat, ist das eine
     * Annahme, und wird sie falsch, wird der falsche Anhang verbucht.
     */
    expect($treffer[0]['wert'])->not->toBe('1');

    // Und das Produkt wurde fuer dieses Feld NICHT gefragt.
    expect($werkzeug->auswahlGefragt)->toBe([])
        ->and($werkzeug->nachrichtGefragt)->toBe(1);
});

it('filtert nach der Frage, ertraegt aber die leere Frage', function () {
    // Die leere Frage ist der Normalfall: Der Auswaehler fragt beim Aufklappen
    // einmal ohne Text.
    $anhaenge = [
        ['index' => 1, 'filename' => 'Rechnung STEU426174784.pdf', 'size' => 1000],
        ['index' => 2, 'filename' => 'Versandanzeige 0010438926.pdf', 'size' => 1000],
    ];

    $alle = anhangsVerzeichnisMit(new AnhangsWerkzeug($anhaenge))
        ->auswahl('beleg', 'anhang', '', anhangsVorgang(), anhangsPerson());

    $gefiltert = anhangsVerzeichnisMit(new AnhangsWerkzeug($anhaenge))
        ->auswahl('beleg', 'anhang', 'versand', anhangsVorgang(), anhangsPerson());

    expect($alle)->toHaveCount(2)
        ->and($gefiltert)->toHaveCount(1)
        ->and($gefiltert[0]['wert'])->toBe('Versandanzeige 0010438926.pdf');
});

it('gibt zwei Felder desselben Verbs auf zwei Wegen heraus', function () {
    /*
     * Die Abgrenzung, auf die es ankommt: Die Art entscheidet, nicht der
     * Vertrag. Ein Verb mit `anhang` UND einem suchbaren Feld bekommt fuer das
     * eine die Liste des Pakets und fuer das andere seine eigene.
     */
    $werkzeug = new AnhangsWerkzeug(
        [['index' => 1, 'filename' => 'Rechnung.pdf', 'size' => 2048]],
        zweitesFeld: true,
    );
    $verzeichnis = anhangsVerzeichnisMit($werkzeug);

    $anhaenge = $verzeichnis->auswahl('beleg', 'anhang', '', anhangsVorgang(), anhangsPerson());
    $projekte = $verzeichnis->auswahl('beleg', 'projekt', 'Meier', anhangsVorgang(), anhangsPerson());

    expect($anhaenge[0]['wert'])->toBe('Rechnung.pdf')
        ->and($projekte[0]['wert'])->toBe('p-1')
        // Gefragt wurde das Produkt genau einmal, und zwar fuer `projekt`.
        ->and($werkzeug->auswahlGefragt)->toBe(['projekt']);
});

it('gibt eine leere Liste, wenn die Mail nicht zu finden ist', function () {
    // Weg, verschoben, oder das Postfach steht dieser Person nicht zu: In
    // allen Faellen eine leere Liste und keine Ausnahme — der Grund gehoert
    // ins Protokoll des Produkts, nicht in die Antwort.
    $treffer = anhangsVerzeichnisMit(new AnhangsWerkzeug(null))
        ->auswahl('beleg', 'anhang', '', anhangsVorgang(), anhangsPerson());

    expect($treffer)->toBe([]);
});

it('gibt nichts heraus, wenn das Verb hier nicht anwendbar ist', function () {
    // Dieselbe Regel wie beim Suchen und beim Ausloesen: `anwendbar()` zuerst.
    // Anhangsnamen sind auch etwas, und eine Liste, die durch vier Systeme
    // gereist ist, ist eine Behauptung.
    $werkzeug = new AnhangsWerkzeug(
        [['index' => 1, 'filename' => 'Geheim.pdf', 'size' => 10]],
        anwendbar: false,
    );

    $treffer = anhangsVerzeichnisMit($werkzeug)
        ->auswahl('beleg', 'anhang', '', anhangsVorgang(), anhangsPerson());

    expect($treffer)->toBe([])
        // Und die Mail wurde gar nicht erst geoeffnet.
        ->and($werkzeug->nachrichtGefragt)->toBe(0);
});

it('wird laut, wenn ein Verb ein Anhangsfeld fuehrt und seine Mail nicht oeffnen kann', function () {
    /*
     * Das ist der Mangel, der Bug #1446 verursacht hat — nur eine Ebene
     * weiter. Leer ist die ehrliche Antwort nach draussen, aber im Log muss es
     * stehen: Ohne die Liste ist der Pflichtknopf von aussen nicht
     * ausfuellbar, und genau dieser Zustand hat zehn Belege liegen lassen.
     */
    Log::spy();

    $treffer = anhangsVerzeichnisMit(new AnhangsWerkzeugOhneMail)
        ->auswahl('beleg', 'anhang', '', anhangsVorgang(), anhangsPerson());

    expect($treffer)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $satz, array $daten): bool => str_contains($satz, 'Anhangsfeld')
            && ($daten['field'] ?? null) === 'anhang'
            && str_contains((string) ($daten['hinweis'] ?? ''), 'OeffnetSeineMail'))
        ->once();
});

it('laesst einen Anhang ohne Namen und ohne Groesse weg beziehungsweise ohne Hinweis stehen', function () {
    // Ein Eintrag ohne Dateinamen waere drueben ein Knopf, der nichts
    // auswaehlt. Und eine fehlende Groesse ist KEINE Groesse von 0 — dann
    // steht eben kein Hinweis da, statt eine falsche Zahl zu behaupten.
    $treffer = anhangsVerzeichnisMit(new AnhangsWerkzeug([
        ['index' => 1, 'filename' => '', 'size' => 100],
        ['index' => 2, 'filename' => 'Ohne Groesse.pdf'],
        ['index' => 3, 'filename' => 'Nullgross.pdf', 'size' => 0],
    ]))->auswahl('beleg', 'anhang', '', anhangsVorgang(), anhangsPerson());

    expect($treffer)->toBe([
        ['wert' => 'Ohne Groesse.pdf', 'wort' => 'Ohne Groesse.pdf'],
        ['wert' => 'Nullgross.pdf', 'wort' => 'Nullgross.pdf'],
    ]);
});
