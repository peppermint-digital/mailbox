<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Http\HandlesWerkzeuge;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Werkzeuge\Ergebnis;
use Peppermint\Mailbox\Werkzeuge\FremdeWerkzeuge;
use Peppermint\Mailbox\Werkzeuge\GesamtWerkzeuge;
use Peppermint\Mailbox\Werkzeuge\LiefertAuswahl;
use Peppermint\Mailbox\Werkzeuge\LiefertFelder;
use Peppermint\Mailbox\Werkzeuge\SuchendeWerkzeugquelle;
use Peppermint\Mailbox\Werkzeuge\Vorgang;
use Peppermint\Mailbox\Werkzeuge\Vorgangswerkzeug;
use Peppermint\Mailbox\Werkzeuge\Werkzeug;
use Peppermint\Mailbox\Werkzeuge\Werkzeugquelle;
use Peppermint\Mailbox\Werkzeuge\Werkzeugverzeichnis;

/**
 * Der Auswaehler mit Suche (1.11.2026, #9145).
 *
 * Ein Kontakt wird aus zwoelftausend gewaehlt, eine Aufgabe aus achthundert.
 * Dafuer gab es bis jetzt nur `verweis()`: Das Verb wurde zum Link und fuehrte
 * ins andere System — genau der Sprung, den dieser Task abbaut.
 *
 * Geprueft wird vor allem, was eine Suche ueber Systemgrenzen still falsch
 * machen kann: Eine Liste, die durch vier Systeme gereist ist, ist eine
 * Behauptung, und ein Eintrag ohne Wert waere drueben ein Knopf, der nichts
 * auswaehlt.
 */
class SuchendesWerkzeug implements LiefertAuswahl, LiefertFelder, Vorgangswerkzeug
{
    public array $gefragt = [];

    public function __construct(
        private readonly array $treffer = [],
        private readonly bool $anwendbar = true,
        private readonly ?string $verweis = null,
        private readonly bool $wirft = false,
    ) {}

    public function werkzeug(): Werkzeug
    {
        return new Werkzeug(schluessel: 'aufgabe', wort: 'Aufgabe anhängen');
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
        return $this->verweis;
    }

    public function ausfuehren(Vorgang $vorgang, array $daten, Model $person): Ergebnis
    {
        return Ergebnis::gelungen('Angehängt.');
    }

    public function felder(Vorgang $vorgang, Model $person): array
    {
        return [['name' => 'aufgabe', 'wort' => 'Aufgabe', 'art' => 'suche', 'pflicht' => true]];
    }

    public function auswahl(Vorgang $vorgang, Model $person, string $feld, string $suche): array
    {
        if ($this->wirft) {
            throw new RuntimeException('Aufgabenliste antwortet nicht.');
        }

        $this->gefragt[] = ['feld' => $feld, 'suche' => $suche, 'postfach' => $vorgang->postfach];

        return $this->treffer;
    }
}

/** Ein Verb MIT suchbarem Feld, aber OHNE Suchvertrag — der Fall, den ein Produkt vergisst. */
class WerkzeugOhneSuche implements LiefertFelder, Vorgangswerkzeug
{
    public function werkzeug(): Werkzeug
    {
        return new Werkzeug(schluessel: 'aufgabe', wort: 'Aufgabe anhängen');
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
        return Ergebnis::gelungen('Angehängt.');
    }

    public function felder(Vorgang $vorgang, Model $person): array
    {
        return [['name' => 'aufgabe', 'wort' => 'Aufgabe', 'art' => 'suche']];
    }
}

/** Eine Quelle, die suchen KANN. */
class SuchendeProbeQuelle implements SuchendeWerkzeugquelle
{
    public array $gefragt = [];

    public function __construct(
        private readonly array $anbieter = [],
        private readonly array $treffer = [],
        private readonly bool $wirft = false,
    ) {}

    public function anbieter(): array
    {
        return $this->anbieter;
    }

    public function holen(string $slug, Vorgang $vorgang, string $adresse): array
    {
        return ['tools' => [], 'failed' => []];
    }

    public function ausloesen(string $slug, string $schluessel, Vorgang $vorgang, array $daten, string $adresse): array
    {
        return ['ok' => true, 'message' => 'Erledigt.'];
    }

    public function auswaehlen(string $slug, string $schluessel, string $feld, string $suche, Vorgang $vorgang, string $adresse): array
    {
        if ($this->wirft) {
            throw new RuntimeException('Gateway weg.');
        }

        $this->gefragt[] = ['slug' => $slug, 'key' => $schluessel, 'feld' => $feld, 'suche' => $suche, 'adresse' => $adresse];

        return $this->treffer;
    }
}

/** Eine Quelle, die NICHT suchen kann — der alte Stand eines Produkts. */
class StummeProbeQuelle implements Werkzeugquelle
{
    public function __construct(private readonly array $anbieter = []) {}

    public function anbieter(): array
    {
        return $this->anbieter;
    }

    public function holen(string $slug, Vorgang $vorgang, string $adresse): array
    {
        return ['tools' => [], 'failed' => []];
    }

    public function ausloesen(string $slug, string $schluessel, Vorgang $vorgang, array $daten, string $adresse): array
    {
        return ['ok' => true, 'message' => 'Erledigt.'];
    }
}

function auswahlPerson(?string $adresse = 'stelljes@peppermint-digital.de'): Model
{
    $person = new class extends Model
    {
        protected $guarded = [];
    };

    $person->setAttribute('email', $adresse);

    return $person;
}

function auswahlVorgang(): Vorgang
{
    return new Vorgang(
        postfach: 'department@peppermint-digital.de',
        kette: 'kette-1',
        nachricht: '<a@example.test>',
        betreff: 'Anfrage Bauvorhaben Meier',
    );
}

function auswahlVerzeichnisMit(Vorgangswerkzeug $werkzeug): Werkzeugverzeichnis
{
    $verzeichnis = new Werkzeugverzeichnis(app());
    $verzeichnis->vergessen();
    $verzeichnis->anmelden($werkzeug);

    return $verzeichnis;
}

describe('das eigene System', function () {
    it('gibt die Treffer des Verbs heraus — mit Feld und Frage', function () {
        $werkzeug = new SuchendesWerkzeug([
            ['wert' => '4471', 'wort' => 'Angebot Meier überarbeiten', 'hinweis' => 'Projekt Bauvorhaben'],
        ]);

        $treffer = auswahlVerzeichnisMit($werkzeug)->auswahl('aufgabe', 'aufgabe', 'Meier', auswahlVorgang(), auswahlPerson());

        expect($treffer)->toBe([
            ['wert' => '4471', 'wort' => 'Angebot Meier überarbeiten', 'hinweis' => 'Projekt Bauvorhaben'],
        ]);

        /*
         * Feld UND Frage kommen an, und der Vorgang auch: Welche Aufgaben in
         * Frage kommen, entscheidet sich am Postfach und manchmal am Absender.
         */
        expect($werkzeug->gefragt[0])->toBe([
            'feld' => 'aufgabe',
            'suche' => 'Meier',
            'postfach' => 'department@peppermint-digital.de',
        ]);
    });

    it('ertraegt die LEERE Frage — sie ist der Normalfall beim Aufklappen', function () {
        $werkzeug = new SuchendesWerkzeug([['wert' => '1', 'wort' => 'Die letzte']]);

        $treffer = auswahlVerzeichnisMit($werkzeug)->auswahl('aufgabe', 'aufgabe', '', auswahlVorgang(), auswahlPerson());

        expect($treffer)->toHaveCount(1)
            ->and($werkzeug->gefragt[0]['suche'])->toBe('');
    });

    it('wirft Eintraege ohne Wert weg — ein Knopf, der nichts auswaehlt, ist schlimmer als keiner', function () {
        $treffer = auswahlVerzeichnisMit(new SuchendesWerkzeug([
            ['wert' => '1', 'wort' => 'Gut'],
            ['wort' => 'Ohne Wert'],
            ['wert' => '', 'wort' => 'Leerer Wert'],
            'kein Array',
        ]))->auswahl('aufgabe', 'aufgabe', '', auswahlVorgang(), auswahlPerson());

        expect($treffer)->toBe([['wert' => '1', 'wort' => 'Gut']]);
    });

    it('nimmt ohne Wort den Wert — ein leerer Knopf ist schlimmer als ein haesslicher', function () {
        $treffer = auswahlVerzeichnisMit(new SuchendesWerkzeug([['wert' => '4471']]))
            ->auswahl('aufgabe', 'aufgabe', '', auswahlVorgang(), auswahlPerson());

        expect($treffer)->toBe([['wert' => '4471', 'wort' => '4471']]);
    });

    it('gibt NICHTS heraus, wenn das Verb hier nicht anwendbar ist', function () {
        /*
         * Dasselbe Schloss wie beim Ausloesen. Suchen legt nichts an, aber es
         * GIBT etwas heraus — und Namen sind auch etwas.
         */
        $treffer = auswahlVerzeichnisMit(new SuchendesWerkzeug([['wert' => '1', 'wort' => 'Geheim']], anwendbar: false))
            ->auswahl('aufgabe', 'aufgabe', '', auswahlVorgang(), auswahlPerson());

        expect($treffer)->toBe([]);
    });

    it('fragt ein Verweis-Verb nicht — es fuehrt hin, es sucht nicht', function () {
        $treffer = auswahlVerzeichnisMit(new SuchendesWerkzeug([['wert' => '1', 'wort' => 'X']], verweis: '/crm/kontakte'))
            ->auswahl('aufgabe', 'aufgabe', '', auswahlVorgang(), auswahlPerson());

        expect($treffer)->toBe([]);
    });

    it('gibt NICHTS heraus, wenn das Verb seinen Suchvertrag vergessen hat', function () {
        /*
         * Ein Produkt kann ein Feld der Art `suche` angeben und
         * `LiefertAuswahl` nicht umsetzen. Die ehrliche Antwort ist „keine
         * Treffer" und kein Absturz — der Grund steht im Protokoll.
         */
        $treffer = auswahlVerzeichnisMit(new WerkzeugOhneSuche)
            ->auswahl('aufgabe', 'aufgabe', 'Meier', auswahlVorgang(), auswahlPerson());

        expect($treffer)->toBe([]);
    });

    it('macht aus einem Absturz im Produktcode eine leere Liste', function () {
        $treffer = auswahlVerzeichnisMit(new SuchendesWerkzeug(wirft: true))
            ->auswahl('aufgabe', 'aufgabe', 'Meier', auswahlVorgang(), auswahlPerson());

        expect($treffer)->toBe([]);
    });

    it('gibt NICHTS heraus fuer ein Verb, das es hier nicht gibt', function () {
        $treffer = auswahlVerzeichnisMit(new SuchendesWerkzeug([['wert' => '1', 'wort' => 'X']]))
            ->auswahl('kontakt', 'kontakt', '', auswahlVorgang(), auswahlPerson());

        expect($treffer)->toBe([]);
    });
});

describe('ueber Systemgrenzen', function () {
    function auswahlGesamtMit(Vorgangswerkzeug $werkzeug, Werkzeugquelle $quelle): GesamtWerkzeuge
    {
        return new GesamtWerkzeuge(auswahlVerzeichnisMit($werkzeug), new FremdeWerkzeuge($quelle));
    }

    it('fragt bei einem fremden Verb das andere System, nicht das eigene', function () {
        $eigenes = new SuchendesWerkzeug([['wert' => 'falsch', 'wort' => 'Eigene Aufgabe']]);
        $quelle = new SuchendeProbeQuelle(
            anbieter: [['slug' => 'manager', 'key' => 'manager', 'label' => 'Projekt-Manager']],
            treffer: [['wert' => '4471', 'wort' => 'Angebot Meier']],
        );

        $treffer = auswahlGesamtMit($eigenes, $quelle)
            ->auswahl('manager', 'aufgabe', 'aufgabe', 'Meier', auswahlVorgang(), auswahlPerson());

        expect($treffer)->toBe([['wert' => '4471', 'wort' => 'Angebot Meier']])
            ->and($eigenes->gefragt)->toBe([]);

        // Die ADRESSE reist mit — das andere System muss wissen, WER fragt.
        expect($quelle->gefragt[0]['adresse'])->toBe('stelljes@peppermint-digital.de')
            ->and($quelle->gefragt[0]['slug'])->toBe('manager');
    });

    it('gibt NICHTS heraus, wenn die Person keine Adresse hat', function () {
        /*
         * Ohne Adresse kann das andere System nicht wissen, wer fragt. Beim
         * Ausloesen steht hier ein Satz; eine Suche hat keine Stelle, an der
         * er hingehoerte.
         */
        $quelle = new SuchendeProbeQuelle(
            anbieter: [['slug' => 'manager', 'key' => 'manager']],
            treffer: [['wert' => '1', 'wort' => 'X']],
        );

        $treffer = auswahlGesamtMit(new SuchendesWerkzeug, $quelle)
            ->auswahl('manager', 'aufgabe', 'aufgabe', '', auswahlVorgang(), auswahlPerson(null));

        expect($treffer)->toBe([])
            ->and($quelle->gefragt)->toBe([]);
    });

    it('gibt NICHTS heraus fuer ein System, das nicht mehr im Register steht', function () {
        /*
         * Ueber das Register, nicht durch Vertrauen: Die Oberflaeche schickt
         * zurueck, was sie bekommen hat, und ein Kuerzel aus einer alten,
         * offenen Registerkarte zeigt sonst auf ein System, das dieser Nutzer
         * nicht mehr benutzen darf.
         */
        $quelle = new SuchendeProbeQuelle(anbieter: [], treffer: [['wert' => '1', 'wort' => 'X']]);

        $treffer = auswahlGesamtMit(new SuchendesWerkzeug, $quelle)
            ->auswahl('manager', 'aufgabe', 'aufgabe', '', auswahlVorgang(), auswahlPerson());

        expect($treffer)->toBe([]);
    });

    it('vertraegt eine Quelle, die das Suchen gar nicht kann', function () {
        /*
         * Der alte Stand eines Produkts mit eigenem Transport. Keine Treffer
         * aus FREMDEN Systemen — die eigenen Verben suchen weiter. Das ist
         * eine ehrliche Teilmenge und kein Fehler; vor allem ist es kein
         * Absturz, und genau darum ist der Suchvertrag an der Quelle optional.
         */
        $eigenes = new SuchendesWerkzeug([['wert' => '1', 'wort' => 'Eigene']]);
        $gesamt = auswahlGesamtMit($eigenes, new StummeProbeQuelle([['slug' => 'manager', 'key' => 'manager']]));

        expect($gesamt->auswahl('manager', 'aufgabe', 'aufgabe', '', auswahlVorgang(), auswahlPerson()))->toBe([])
            // Und das eigene Verb sucht weiter.
            ->and($gesamt->auswahl(null, 'aufgabe', 'aufgabe', '', auswahlVorgang(), auswahlPerson()))
            ->toBe([['wert' => '1', 'wort' => 'Eigene']]);
    });

    it('macht aus einem Fehlschlag drueben eine leere Liste', function () {
        $quelle = new SuchendeProbeQuelle(anbieter: [['slug' => 'manager', 'key' => 'manager']], wirft: true);

        expect(auswahlGesamtMit(new SuchendesWerkzeug, $quelle)
            ->auswahl('manager', 'aufgabe', 'aufgabe', '', auswahlVorgang(), auswahlPerson()))->toBe([]);
    });

    it('fragt gar nicht, wenn die Nachricht nicht zu finden ist', function () {
        /*
         * Ein Vorgang ohne Postfach und Nachricht meint keine bestimmte Mail.
         * Dann ist auch keine Suche gemeint.
         */
        $quelle = new SuchendeProbeQuelle(anbieter: [['slug' => 'manager', 'key' => 'manager']], treffer: [['wert' => '1', 'wort' => 'X']]);
        $halb = new Vorgang(postfach: '', kette: null, nachricht: '');

        expect(auswahlGesamtMit(new SuchendesWerkzeug, $quelle)->auswahl('manager', 'aufgabe', 'aufgabe', '', $halb, auswahlPerson()))->toBe([])
            ->and($quelle->gefragt)->toBe([]);
    });
});

describe('die dritte Route', function () {
    /*
     * Hier wird die DRAHTFORM geprueft, und das ist der Teil, der still
     * driften kann: Innen heissen die Felder `wert`, `wort`, `hinweis`, aussen
     * `value`, `label`, `hint`. Dieselbe Regel wie bei den Feldern und beim
     * Zustand — sonst schickt dieses System `wort` und das naechste liest
     * `label`, und die Angabe faellt lautlos weg.
     */
    function auswahlController(Vorgangswerkzeug $werkzeug, ?Model $person = null): object
    {
        $verzeichnis = auswahlVerzeichnisMit($werkzeug);

        app()->instance(GesamtWerkzeuge::class, new GesamtWerkzeuge($verzeichnis, new FremdeWerkzeuge(new StummeProbeQuelle)));
        app()->instance(AccountStore::class, new class implements AccountStore
        {
            public function all(?int $ownerId = null): Collection
            {
                return collect();
            }

            public function find(string|int $id): ?MailAccount
            {
                return MailAccount::fromRemote([
                    'id' => 1,
                    'email' => 'department@peppermint-digital.de',
                    'label' => 'Einkauf',
                    'auth_type' => 'password',
                    'username' => 'einkauf',
                    'password' => 'geheim',
                ]);
            }

            public function isWritable(): bool
            {
                return false;
            }
        });

        return new class($person)
        {
            use HandlesWerkzeuge;

            public function __construct(private ?Model $person) {}

            protected function werkzeugPerson(): ?Model
            {
                return $this->person;
            }

            protected function guardWerkzeuge(int $account): void {}
        };
    }

    function auswahlNutzlast(array $felder): Request
    {
        $anfrage = Request::create('/mailbox/1/tools/options', 'POST', $felder);
        $anfrage->headers->set('Accept', 'application/json');

        return $anfrage;
    }

    it('gibt die Treffer in der Drahtform heraus', function () {
        $antwort = auswahlController(
            new SuchendesWerkzeug([['wert' => '4471', 'wort' => 'Angebot Meier', 'hinweis' => 'Bauvorhaben']]),
            auswahlPerson(),
        )->toolOptions(auswahlNutzlast([
            'action' => 'aufgabe',
            'field' => 'aufgabe',
            'query' => 'Meier',
            'message_id' => '<a@example.test>',
        ]), 1);

        expect($antwort->getData(true))->toBe([
            'options' => [['value' => '4471', 'label' => 'Angebot Meier', 'hint' => 'Bauvorhaben']],
        ]);
    });

    it('laesst `hint` weg, wenn es keinen gibt — und schickt kein `null`', function () {
        /*
         * Ein `hint: null` waere in der Oberflaeche eine Zeile mit nichts
         * darin. Die Drahtform traegt nur, was es gibt.
         */
        $antwort = auswahlController(new SuchendesWerkzeug([['wert' => '1', 'wort' => 'Ohne Hinweis']]), auswahlPerson())
            ->toolOptions(auswahlNutzlast([
                'action' => 'aufgabe',
                'field' => 'aufgabe',
                'message_id' => '<a@example.test>',
            ]), 1);

        expect($antwort->getData(true)['options'][0])->toBe(['value' => '1', 'label' => 'Ohne Hinweis']);
    });

    it('nimmt eine Anfrage OHNE `query` an — das ist das Aufklappen', function () {
        /*
         * `nullable` und nicht `required`: Sonst waere der erste Griff des
         * Auswaehlers eine 422, und die sieht aus wie „die Suche ist kaputt".
         */
        $antwort = auswahlController(new SuchendesWerkzeug([['wert' => '1', 'wort' => 'Die letzte']]), auswahlPerson())
            ->toolOptions(auswahlNutzlast([
                'action' => 'aufgabe',
                'field' => 'aufgabe',
                'message_id' => '<a@example.test>',
            ]), 1);

        expect($antwort->getStatusCode())->toBe(200)
            ->and($antwort->getData(true)['options'])->toHaveCount(1);
    });

    it('antwortet ohne angemeldete Person leer und mit 200', function () {
        /*
         * Anders als beim Ausloesen, wo „nicht angemeldet" ein Riegel vor
         * etwas ist, das entstehen soll. Hier ist es eine Suche, die niemand
         * gestellt hat — ein 401 waere eine rote Meldung an einem Feld, in das
         * jemand gerade tippt.
         */
        $antwort = auswahlController(new SuchendesWerkzeug([['wert' => '1', 'wort' => 'X']]), null)
            ->toolOptions(auswahlNutzlast([
                'action' => 'aufgabe',
                'field' => 'aufgabe',
                'message_id' => '<a@example.test>',
            ]), 1);

        expect($antwort->getStatusCode())->toBe(200)
            ->and($antwort->getData(true))->toBe(['options' => []]);
    });

    it('verlangt das Feld — eine Suche ohne Feld ist keine Suche', function () {
        expect(fn () => auswahlController(new SuchendesWerkzeug, auswahlPerson())->toolOptions(auswahlNutzlast([
            'action' => 'aufgabe',
            'message_id' => '<a@example.test>',
        ]), 1))->toThrow(ValidationException::class);
    });
});
