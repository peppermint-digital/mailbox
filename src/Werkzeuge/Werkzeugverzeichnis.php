<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Welche Verben DIESES System an einer Mail anmeldet.
 *
 * Angemeldet wird im Dienstanbieter des Produkts, neben allem anderen, was
 * das Produkt beisteuert:
 *
 *     app(Werkzeugverzeichnis::class)->anmelden(AlsEingangsrechnung::class);
 *
 * Ein Produkt, das das Paket installiert und nichts anmeldet, hat eine leere
 * Leiste. Das ist der richtige Ausgangszustand — die Verben sind das Produkt,
 * und ein Paket, das welche mitbringt, braechte eine Fachlichkeit mit.
 *
 * ## Ein Werkzeug, das faellt, nimmt die Leiste nicht mit
 *
 * Jedes wird einzeln gefragt und einzeln abgesichert. Ein Ticketsystem, das
 * gerade wandert, darf nicht dazu fuehren, dass auch „Aufgabe anhaengen"
 * verschwindet — das waere genau die Art Ausfall, die wie „es gibt hier
 * nichts zu tun" aussieht.
 *
 * Was ausfiel, steht im Ergebnis. Die Oberflaeche sagt es, statt eine
 * verkuerzte Leiste als vollstaendig auszugeben.
 *
 * ## Spaet aufgeloest, mit Absicht
 *
 * Angemeldet wird die Klasse, gebaut wird erst beim Fragen. Ein Werkzeug darf
 * im Konstruktor ein Repository verlangen; wuerde das beim Start passieren,
 * haengte der Start des Produkts an der Erreichbarkeit seiner Werkzeuge.
 */
class Werkzeugverzeichnis
{
    /** @var array<int, class-string<Vorgangswerkzeug>|Vorgangswerkzeug> */
    private array $werkzeuge = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<Vorgangswerkzeug>|Vorgangswerkzeug $werkzeug */
    public function anmelden(string|Vorgangswerkzeug $werkzeug): self
    {
        $this->werkzeuge[] = $werkzeug;

        return $this;
    }

    /** @param array<int, class-string<Vorgangswerkzeug>|Vorgangswerkzeug> $werkzeuge */
    public function anmeldenViele(array $werkzeuge): self
    {
        foreach ($werkzeuge as $werkzeug) {
            $this->anmelden($werkzeug);
        }

        return $this;
    }

    /** @return array<int, Vorgangswerkzeug> */
    public function alle(): array
    {
        return array_map(
            fn (string|Vorgangswerkzeug $w): Vorgangswerkzeug => is_string($w) ? $this->container->make($w) : $w,
            $this->werkzeuge,
        );
    }

    /**
     * Die eigenen Verben fuer diesen Vorgang, mit Zustand.
     *
     * @param  bool  $fuerFremdeSysteme  Gefragt von draussen? Dann bleiben
     *                                   {@see NurHier}-Verben hier. Siehe dort,
     *                                   warum: Ein Verb, das jedes System
     *                                   selbst hat, kaeme sonst vierfach.
     * @return array{werkzeuge: array<int, Werkzeug>, failed: array<int, string>}
     */
    public function sammeln(Vorgang $vorgang, Model $person, bool $fuerFremdeSysteme = false): array
    {
        $gefunden = [];
        $gescheitert = [];
        $gesehen = [];

        foreach ($this->alle() as $werkzeug) {
            try {
                if ($fuerFremdeSysteme && $werkzeug instanceof NurHier) {
                    continue;
                }

                $form = $werkzeug->werkzeug();

                if (isset($gesehen[$form->schluessel])) {
                    // Zwei Werkzeuge mit demselben Schluessel: Das Ausloesen
                    // waere ab hier Zufall. Laut bleiben — stillschweigend
                    // eines zu verwerfen ist der Fehler, den man erst
                    // bemerkt, wenn der falsche Vorgang ausgeloest wurde.
                    Log::warning('Zwei Mail-Werkzeuge tragen denselben Schluessel.', [
                        'key' => $form->schluessel,
                        'kept' => $gesehen[$form->schluessel],
                        'dropped' => $werkzeug::class,
                    ]);

                    continue;
                }

                $gesehen[$form->schluessel] = $werkzeug::class;

                if (! $werkzeug->anwendbar($vorgang, $person)) {
                    continue;
                }

                $fertig = $form
                    ->mitZustand($werkzeug->zustand($vorgang, $person))
                    // Die Adresse haengt am Vorgang, nicht an der Form —
                    // deshalb erst hier und nicht beim Anmelden.
                    ->mitVerweis($werkzeug->verweis($vorgang, $person));

                // Felder nur, wenn das Verb sie ueberhaupt fuehrt. `instanceof`
                // statt einer vierten Pflichtmethode: Die drei vorhandenen
                // Produkt-Werkzeuge sollen vom naechsten Paket-Update nicht
                // brechen, siehe {@see LiefertFelder}.
                if ($werkzeug instanceof LiefertFelder) {
                    $fertig = $fertig->mitFeldern($werkzeug->felder($vorgang, $person));
                }

                $gefunden[] = $fertig;
            } catch (\Throwable $fehler) {
                $gescheitert[] = $this->namen($werkzeug);

                Log::warning('Mail-Werkzeug hat nicht geantwortet.', [
                    'tool' => $werkzeug::class,
                    'error' => $fehler->getMessage(),
                ]);
            }
        }

        return ['werkzeuge' => $gefunden, 'failed' => $gescheitert];
    }

    /**
     * Ein eigenes Verb ausloesen.
     *
     * ## Zwei Schloesser, und beide sind Absicht
     *
     * Hier stand bis zum 20.10.2026, die Anwendbarkeit werde ABSICHTLICH nicht
     * geprueft — das Werkzeug pruefe selbst. Das tun die Werkzeuge auch; jedes
     * `ausfuehren()` fragt seine Rechte neu, und das bleibt so. Die Begruendung
     * war trotzdem zu duenn, und sie ist von der Session calendar-26 ueber den
     * Waechter `McpWriteToolsAreAuthorizedTest` des Projekt-Managers
     * aufgebrochen worden: Von aussen betrachtet hatte der Weg
     * „fremdes System → MCP → Verzeichnis → Verb" keine sichtbare Pruefung.
     *
     * Jetzt pruefen beide. Warum das KEINE doppelte Arbeit ist:
     *
     * - `anwendbar()` ist in allen Verben genau die Rechte- und
     *   Sichtbarkeitsfrage, nichts anderes. Zustandsgruende („das Ticket gibt
     *   es schon") wohnen in `zustand()`. Ein Riegel darauf verweigert also
     *   nur, was ohnehin verweigert gehoert.
     * - Dies ist der ENGPASS. Jeder Aufruf aus einem anderen System laeuft
     *   hier durch. Ein neues Verb, dessen Autor die Pruefung in
     *   `ausfuehren()` vergisst, oeffnet sonst ein Loch, das aus drei
     *   Nachbarsystemen erreichbar ist.
     * - Das alte Gegenargument — „eine Pruefung, die zwischen Auflisten und
     *   Ausloesen veraltet" — trifft nicht: Hier wird unmittelbar vor dem
     *   Ausloesen gefragt, im selben Aufruf. Veralten kann eine
     *   zwischengespeicherte LISTE, nicht eine Frage, die man neu stellt.
     * - Das zweite Gegenargument — „ein Werkzeug, das sich auf eine Pruefung
     *   verlaesst, die es nicht sieht" — bleibt richtig, und deshalb ist der
     *   Riegel hier ZUSAETZLICH. Er nimmt keinem Verb seine eigene Pruefung
     *   ab; `tests/Feature/WerkzeugverzeichnisPruefungTest.php` haelt beides
     *   fest.
     *
     * Dasselbe sagt der Doc-Block von `TicketAusMail::anwendbar()` aus der
     * anderen Richtung: „Diese Liste reist durch den Verbund, und was durch
     * vier Systeme gereist ist, ist eine Behauptung." Eine Behauptung prueft
     * man an der Stelle nach, an der gehandelt wird.
     *
     * @param  array<string, mixed>  $daten
     */
    public function ausfuehren(string $schluessel, Vorgang $vorgang, array $daten, Model $person): Ergebnis
    {
        foreach ($this->alle() as $werkzeug) {
            try {
                $form = $werkzeug->werkzeug();

                if ($form->schluessel !== $schluessel) {
                    continue;
                }

                /*
                 * Das erste Schloss. Siehe Doc-Block: `anwendbar()` ist in
                 * jedem Verb die Rechte- und Sichtbarkeitsfrage.
                 *
                 * Dieselbe Antwort fuer „darf nicht" und „Postfach gibt es
                 * hier nicht" ist Absicht, und die Begruendung steht in
                 * `TicketAusMail`: Wer ein Postfach nicht sehen darf, soll
                 * nicht erfahren, dass es existiert.
                 */
                if (! $werkzeug->anwendbar($vorgang, $person)) {
                    return Ergebnis::gescheitert('Dieses Werkzeug steht für diese Nachricht nicht zur Verfügung.');
                }

                // Die Form allein weiss noch nicht, ob dieses Verb hinfuehrt:
                // Die Adresse kommt vom Vorgang. Also hier fragen, mit
                // demselben Vorgang, mit dem auch die Leiste gebaut wurde.
                if ($form->mitVerweis($werkzeug->verweis($vorgang, $person))->fuehrtHin()) {
                    /*
                     * Ein Verweis-Verb wird nie ausgeloest — es FUEHRT HIN.
                     * Die Regel steht hier und nicht in der Oberflaeche, weil
                     * sie auch fuer einen Aufruf aus einem anderen System
                     * gelten muss: Dessen Leiste kennt die Form, aber ein
                     * veralteter Stand drueben koennte es trotzdem versuchen.
                     *
                     * `ausfuehren()` ist bei so einem Verb meist nicht
                     * sinnvoll umgesetzt. Es trotzdem zu rufen hiesse, auf
                     * gut Glueck eine Methode zu nehmen, die nie dafuer
                     * gedacht war.
                     */
                    return Ergebnis::gescheitert('Dieses Verb führt zum Zielsystem; dort wird es ausgelöst.');
                }

                return $werkzeug->ausfuehren($vorgang, $daten, $person);
            } catch (\Throwable $fehler) {
                Log::warning('Mail-Werkzeug ist beim Ausfuehren gescheitert.', [
                    'tool' => $werkzeug::class,
                    'key' => $schluessel,
                    'error' => $fehler->getMessage(),
                ]);

                return Ergebnis::gescheitert('Das Werkzeug konnte den Vorgang nicht abschliessen.');
            }
        }

        // Nicht „nichts passiert": Ein unbekannter Schluessel heisst, dass die
        // Oberflaeche ein Verb anbietet, das es hier nicht gibt — meist ein
        // System, das einen Schritt weiter ausgerollt ist.
        return Ergebnis::gescheitert('Dieses Werkzeug gibt es in diesem System nicht.');
    }

    /**
     * Treffer fuer ein suchbares Feld — aus dem EIGENEN System.
     *
     * Bewusst eng gebaut und mit denselben Schloessern wie
     * {@see ausfuehren()}: `anwendbar()` zuerst, und ein Verweis-Verb wird
     * nicht gefragt. Suchen legt nichts an, aber es GIBT etwas heraus, und
     * Namen sind auch etwas.
     *
     * Eine leere Liste ist die Antwort auf alles, was nicht geht — kein
     * Werkzeug, kein Suchvertrag, nicht anwendbar, Absturz im Produktcode.
     * Der Grund steht im Protokoll und nicht in der Antwort: Ein Auswaehler,
     * der erklaert, WARUM er nichts findet, erzaehlt dem Fragenden etwas ueber
     * ein System, das er vielleicht nicht sehen darf.
     *
     * @return array<int, array{wert: string, wort: string, hinweis?: string}>
     */
    public function auswahl(string $schluessel, string $feld, string $suche, Vorgang $vorgang, Model $person): array
    {
        foreach ($this->alle() as $werkzeug) {
            try {
                $form = $werkzeug->werkzeug();

                if ($form->schluessel !== $schluessel) {
                    continue;
                }

                if (! $werkzeug instanceof LiefertAuswahl) {
                    /*
                     * Das Verb gibt es, aber es sucht nicht. Das ist kein
                     * Fehler der Oberflaeche: Ein Produkt kann ein Feld
                     * `suche` angeben und den Vertrag vergessen haben, und
                     * dann ist die ehrliche Antwort „keine Treffer" statt
                     * eines Absturzes.
                     */
                    Log::warning('Mail-Werkzeug hat ein suchbares Feld, aber keinen Suchvertrag.', [
                        'tool' => $werkzeug::class,
                        'key' => $schluessel,
                        'field' => $feld,
                    ]);

                    return [];
                }

                if (! $werkzeug->anwendbar($vorgang, $person)) {
                    return [];
                }

                // Ein Verweis-Verb wird nicht gefragt — dieselbe Regel wie
                // beim Ausloesen, und aus demselben Grund: Ein veralteter
                // Stand drueben koennte es trotzdem versuchen.
                if ($form->mitVerweis($werkzeug->verweis($vorgang, $person))->fuehrtHin()) {
                    return [];
                }

                return $this->sauber($werkzeug->auswahl($vorgang, $person, $feld, $suche));
            } catch (\Throwable $fehler) {
                Log::warning('Mail-Werkzeug ist beim Suchen gescheitert.', [
                    'tool' => $werkzeug::class,
                    'key' => $schluessel,
                    'field' => $feld,
                    'error' => $fehler->getMessage(),
                ]);

                return [];
            }
        }

        return [];
    }

    /**
     * Die Treffer auf die Form bringen, in der sie hinausgehen.
     *
     * Hier und nicht in der Oberflaeche, weil die Liste ueber Systemgrenzen
     * reist: Was ein Produkt zurueckgibt, ist Produktcode, und ein Eintrag
     * ohne `wert` waere drueben ein Knopf, der nichts auswaehlt.
     *
     * @param  array<int, array<string, mixed>>  $roh
     * @return array<int, array{wert: string, wort: string, hinweis?: string}>
     */
    private function sauber(array $roh): array
    {
        $treffer = [];

        foreach ($roh as $eintrag) {
            if (! is_array($eintrag)) {
                continue;
            }

            $wert = (string) ($eintrag['wert'] ?? '');

            if ($wert === '') {
                continue;
            }

            $sauber = [
                'wert' => $wert,
                // Ohne Wort der Wert: Ein leerer Knopf ist schlimmer als ein
                // haesslicher.
                'wort' => (string) ($eintrag['wort'] ?? '') !== '' ? (string) $eintrag['wort'] : $wert,
            ];

            if ((string) ($eintrag['hinweis'] ?? '') !== '') {
                $sauber['hinweis'] = (string) $eintrag['hinweis'];
            }

            $treffer[] = $sauber;
        }

        return $treffer;
    }

    /** Fuer Tests. */
    public function vergessen(): void
    {
        $this->werkzeuge = [];
    }

    private function namen(Vorgangswerkzeug $werkzeug): string
    {
        try {
            return $werkzeug->werkzeug()->wort;
        } catch (\Throwable) {
            // Wenn schon `werkzeug()` wirft, bleibt der Klassenname. Besser
            // als eine leere Zeile in der Ausfall-Liste.
            return class_basename($werkzeug);
        }
    }
}
