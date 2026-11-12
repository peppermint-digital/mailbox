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
    /**
     * Die Feldart, deren Auswahl das PAKET liefert.
     *
     * Als Konstante, weil zwei Stellen sie kennen muessen: {@see LiefertFelder}
     * sichert sie in seiner Tabelle zu, und {@see self::istAnhangsfeld()}
     * erkennt sie. Ein Literal an beiden Stellen waere die Stelle, an der ein
     * Tippfehler die Auswahl still wieder leer macht — und leer sah bis zum
     * 12.11.2026 aus wie „diese Mail hat keine Anhaenge".
     */
    public const ART_ANHANG = 'anhang';

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

                /*
                 * Die Feldart `anhang` beantwortet das PAKET (#9856).
                 *
                 * Sie gehoert nicht in den Suchvertrag des Produkts, und bis
                 * zum 12.11.2026 landete sie genau dort: Ein Verb mit einem
                 * `anhang`-Feld bekam die Warnung „suchbares Feld, aber kein
                 * Suchvertrag" und der Fragende eine leere Liste. Fuer die
                 * eigene Leiste fiel das nie auf — die hat die Mail offen und
                 * zeichnet die Anhaenge selbst. Ein anderes System hatte
                 * damit einen Pflichtknopf, den es nicht ausfuellen konnte
                 * (Verwaltung, Bug #1446: zehn Belege blieben liegen).
                 *
                 * VOR der Suchvertrag-Pruefung, weil die Art die Frage
                 * entscheidet und nicht der Vertrag: Ein Verb, das BEIDES hat
                 * — ein `anhang`-Feld und ein suchbares Feld daneben — soll
                 * fuer das eine das Paket antworten lassen und fuer das
                 * andere selbst.
                 */
                if ($this->istAnhangsfeld($werkzeug, $feld, $vorgang, $person)) {
                    if (! $werkzeug->anwendbar($vorgang, $person)) {
                        return [];
                    }

                    return $this->anhaenge($werkzeug, $feld, $suche, $vorgang, $person);
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
     * Fuehrt dieses Verb ein Feld dieses Namens mit der Art `anhang`?
     *
     * Gefragt wird an {@see LiefertFelder::felder()} und nicht an einer
     * eigenen Liste: Die Art steht dort, wo das Verb sie erklaert hat. Eine
     * zweite Stelle, die „welche Felder sind Anhangsfelder" beantwortet, waere
     * genau die Kopie, die beim naechsten Feld zurueckbleibt.
     *
     * Ein Verb ohne Felder ({@see LiefertFelder} nicht umgesetzt) hat auch
     * kein Anhangsfeld — dann gilt der gewohnte Weg.
     */
    private function istAnhangsfeld(Vorgangswerkzeug $werkzeug, string $feld, Vorgang $vorgang, Model $person): bool
    {
        if (! $werkzeug instanceof LiefertFelder) {
            return false;
        }

        foreach ($werkzeug->felder($vorgang, $person) as $eines) {
            if (! is_array($eines)) {
                continue;
            }

            if ((string) ($eines['name'] ?? '') === $feld) {
                return (string) ($eines['art'] ?? '') === self::ART_ANHANG;
            }
        }

        return false;
    }

    /**
     * Die Anhaenge DIESER Mail als Auswahl.
     *
     * ## Warum der Dateiname der Wert ist und nicht die Stelle
     *
     * Weil {@see LiefertFelder} es so zusichert und
     * {@see Nachrichtenfund::stelle()} den Grund nennt: Die Stelle stimmt nur,
     * solange beide Seiten dieselbe Mail in derselben Reihenfolge sehen. Bei
     * einer Mail, die ein anderes System geholt hat, ist das eine Annahme —
     * und wird sie falsch, wird der falsche Anhang verbucht.
     *
     * ## Warum eingebettete Bilder nicht dabei sind
     *
     * Sie stehen in `attachments` gar nicht: {@see Imap\MessageFormatter}
     * sortiert sie als `inline` aus. Das Logo aus der Signatur taucht hier
     * also nicht als waehlbarer Beleg auf, und das ist richtig — es waere der
     * erste Treffer in jeder Liste.
     *
     * Die Groesse steht als `hinweis` daneben. Sie ist das Einzige, woran ein
     * Mensch zwei gleichnamige Anhaenge unterscheiden kann, und sie verraet
     * ausserdem eine halbe Datei, bevor jemand sie verbucht.
     *
     * @return array<int, array{wert: string, wort: string, hinweis?: string}>
     */
    private function anhaenge(Vorgangswerkzeug $werkzeug, string $feld, string $suche, Vorgang $vorgang, Model $person): array
    {
        if (! $werkzeug instanceof OeffnetSeineMail) {
            /*
             * Das Verb fuehrt ein Anhangsfeld, kann seine Mail aber nicht
             * oeffnen. Dann ist die Liste nicht zu haben — und das ist ein
             * Mangel des Verbs und keine Eigenschaft der Mail. Laut, damit es
             * auffaellt: Ohne die Liste ist der Pflichtknopf von aussen nicht
             * ausfuellbar, und genau dieser Zustand hat in der Verwaltung zehn
             * Belege liegen lassen (Bug #1446).
             */
            Log::warning('Mail-Werkzeug hat ein Anhangsfeld, kann seine Mail aber nicht oeffnen.', [
                'tool' => $werkzeug::class,
                'field' => $feld,
                'hinweis' => 'Vertrag '.OeffnetSeineMail::class.' umsetzen.',
            ]);

            return [];
        }

        $fund = $werkzeug->nachricht($vorgang, $person);

        if ($fund === null) {
            // Mail nicht (mehr) da oder Postfach nicht sichtbar. Eine leere
            // Liste, wie bei jeder anderen Absage — der Grund gehoert ins
            // Protokoll des Produkts.
            return [];
        }

        $liste = $fund->nachricht['attachments'] ?? [];
        $frage = mb_strtolower(trim($suche));
        $treffer = [];

        foreach (is_array($liste) ? $liste : [] as $anhang) {
            if (! is_array($anhang)) {
                continue;
            }

            $name = (string) ($anhang['filename'] ?? '');

            if ($name === '') {
                continue;
            }

            // Die leere Frage ist der Normalfall (der Auswaehler fragt beim
            // Aufklappen einmal ohne Text) und zeigt dann alles: Eine Mail
            // hat zwei, drei Anhaenge, keine zweitausend.
            if ($frage !== '' && ! str_contains(mb_strtolower($name), $frage)) {
                continue;
            }

            $treffer[] = array_filter([
                'wert' => $name,
                'wort' => $name,
                'hinweis' => self::groesse($anhang['size'] ?? null),
            ], static fn ($wert): bool => $wert !== null);
        }

        return $treffer;
    }

    /**
     * Die Groesse als Satz — oder `null`, wenn keine mitkam.
     *
     * Kein `0 B` fuer eine fehlende Angabe: Das waere eine Aussage, und zwar
     * eine falsche. Fehlt die Zahl, steht kein Hinweis da.
     */
    private static function groesse(mixed $bytes): ?string
    {
        if (! is_int($bytes) && ! (is_string($bytes) && ctype_digit($bytes))) {
            return null;
        }

        $zahl = (int) $bytes;

        if ($zahl <= 0) {
            return null;
        }

        return $zahl >= 1024
            ? number_format($zahl / 1024, 0, ',', '.').' KB'
            : $zahl.' B';
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
