<?php

namespace Peppermint\Mailbox\Werkzeuge;

/**
 * Ein Verb an einer Mail: „Als Eingangsrechnung", „Ticket erstellen",
 * „Aufgabe anhaengen", „Kontakt zuordnen".
 *
 * ## Warum das Paket diese Verben nicht kennt
 *
 * Es darf sie nicht kennen. Grundsatz (Wiki #382): Zwei Core-Pakete duerfen
 * sich nicht kennen — ein Postfach-Paket, das `tasks` importiert, ist ohne
 * die Aufgabenverwaltung nicht mehr auslieferbar. Also beschreibt das Paket
 * nur die FORM eines Verbs; was es bedeutet, weiss allein das Produkt, das
 * es anmeldet.
 *
 * ## Herkunft setzt der Fragende, nicht der Befragte
 *
 * `system` und `systemWort` bleiben beim Anmelden leer. Gefuellt werden sie
 * von {@see FremdeWerkzeuge} — aus demselben Grund wie bei der Inbox: Ein
 * System weiss nicht, unter welchem Kuerzel es anderswo gefuehrt wird, und
 * duerfte es sich selbst geben, stuenden in vier Oberflaechen vier
 * verschiedene Woerter. Ein eigenes Werkzeug traegt `null` — „von hier".
 *
 * ## `zustand`: warum ein Verb auch etwas BERICHTEN darf
 *
 * „Ticket erstellen" zweimal geklickt sind zwei Tickets. Das Paket kann das
 * nicht verhindern — es weiss nicht, was ein Ticket ist. Das Zielsystem weiss
 * es: Es fuehrt die Verknuepfung (`contact_email_threads`, `TaskEmailThread`,
 * den Belegbezug). Also darf es beim Auflisten sagen, dass es die Sache schon
 * gibt, und die Leiste zeigt statt des Knopfes einen Verweis darauf.
 *
 * Ohne das waere eine zusammengelegte Leiste eine Reihe gleich aussehender
 * Knoepfe, von denen man drei schon gedrueckt hat — und das sieht man erst im
 * Zielsystem.
 */
final class Werkzeug
{
    /**
     * @param  string  $schluessel  Eindeutig INNERHALB eines Systems. Zwei Systeme duerfen denselben tragen.
     * @param  string  $wort  Was auf dem Knopf steht. Vom Produkt, in seiner Sprache.
     * @param  string  $symbol  Name eines Lucide-Symbols.
     * @param  int  $reihenfolge  Kleiner steht weiter vorne.
     * @param  string|null  $gruppe  Verben derselben Gruppe stehen in der Leiste beieinander.
     * @param  array<int, array<string, mixed>>  $felder  Was vor dem Ausloesen gefragt wird. Leer = sofort ausloesen.
     * @param  string|null  $rueckfrage  Gesetzt = Sicherheitsfrage mit diesem Satz.
     * @param  array{wort: string, verweis?: string|null}|null  $zustand  Gibt es die Sache schon?
     * @param  string|null  $verweis  Gesetzt = das Verb FUEHRT HIN, statt zu tun.
     */
    public function __construct(
        public readonly string $schluessel,
        public readonly string $wort,
        public readonly string $symbol = 'wrench',
        public readonly int $reihenfolge = 100,
        public readonly ?string $gruppe = null,
        public readonly array $felder = [],
        public readonly ?string $rueckfrage = null,
        public readonly ?array $zustand = null,
        public readonly ?string $verweis = null,
        public readonly ?string $system = null,
        public readonly ?string $systemWort = null,
    ) {}

    /**
     * Fuehrt dieses Verb nur hin, statt zu tun?
     *
     * ## Warum es diese Sorte Verb geben MUSS
     *
     * Aufgefallen am ersten echten Fall: „Kontakt zuordnen" im CRM braucht
     * einen Kontakt — also einen Auswaehler mit Suche. Den kann ein fremdes
     * System nicht zeichnen, und ein Textfeld fuer eine Kontakt-Nummer waere
     * eine Zumutung.
     *
     * Die ehrliche Form ist dann ein Verweis: „Im CRM zuordnen" fuehrt dorthin,
     * wo der Auswaehler steht. Das Gegenteil waere, ein Verb anzubieten, das
     * drueben an einer fehlenden Angabe scheitert — ein Knopf, der zuverlaessig
     * nicht funktioniert.
     *
     * Ein Verweis-Verb wird NIE ausgeloest. Die Oberflaeche macht daraus einen
     * Link; `ausfuehren()` wird fuer ihn nicht gerufen.
     */
    public function fuehrtHin(): bool
    {
        return $this->verweis !== null && $this->verweis !== '';
    }

    /**
     * Dasselbe Werkzeug, einem System zugeschrieben.
     *
     * Nur {@see FremdeWerkzeuge} und {@see GesamtWerkzeuge} rufen das.
     */
    public function vonSystem(?string $system, ?string $wort): self
    {
        return new self(
            schluessel: $this->schluessel,
            wort: $this->wort,
            symbol: $this->symbol,
            reihenfolge: $this->reihenfolge,
            gruppe: $this->gruppe,
            felder: $this->felder,
            rueckfrage: $this->rueckfrage,
            zustand: $this->zustand,
            verweis: $this->verweis,
            system: $system,
            systemWort: $wort,
        );
    }

    /**
     * Dasselbe Werkzeug mit einem Zustand.
     *
     * Getrennt vom Anmelden, weil der Zustand an einem VORGANG haengt und die
     * Anmeldung am System: Das Verzeichnis wird einmal beim Start gefuellt,
     * den Zustand gibt es erst, wenn eine Mail offen ist.
     *
     * @param  array{wort: string, verweis?: string|null}|null  $zustand
     */
    public function mitZustand(?array $zustand): self
    {
        return new self(
            schluessel: $this->schluessel,
            wort: $this->wort,
            symbol: $this->symbol,
            reihenfolge: $this->reihenfolge,
            gruppe: $this->gruppe,
            felder: $this->felder,
            rueckfrage: $this->rueckfrage,
            zustand: $zustand,
            verweis: $this->verweis,
            system: $this->system,
            systemWort: $this->systemWort,
        );
    }

    /**
     * Dasselbe Werkzeug mit seinen Eingabefeldern.
     *
     * Wie {@see mitZustand()} getrennt vom Anmelden, und aus demselben Grund:
     * Welche Personen zur Auswahl stehen, haengt am POSTFACH — wer an `info@`
     * zuweisen darf, ist eine andere Menge als bei `buchhaltung@`. In
     * `werkzeug()` koennte das nur geraten werden.
     *
     * Siehe {@see LiefertFelder} fuer die Form eines Feldes.
     *
     * @param  array<int, array<string, mixed>>  $felder
     */
    public function mitFeldern(array $felder): self
    {
        return new self(
            schluessel: $this->schluessel,
            wort: $this->wort,
            symbol: $this->symbol,
            reihenfolge: $this->reihenfolge,
            gruppe: $this->gruppe,
            felder: $felder,
            rueckfrage: $this->rueckfrage,
            zustand: $this->zustand,
            verweis: $this->verweis,
            system: $this->system,
            systemWort: $this->systemWort,
        );
    }

    /**
     * Dasselbe Werkzeug mit seiner Adresse.
     *
     * Wie {@see mitZustand()} getrennt vom Anmelden: Die Adresse haengt am
     * Vorgang, die Anmeldung am System.
     */
    public function mitVerweis(?string $verweis): self
    {
        return new self(
            schluessel: $this->schluessel,
            wort: $this->wort,
            symbol: $this->symbol,
            reihenfolge: $this->reihenfolge,
            gruppe: $this->gruppe,
            felder: $this->felder,
            rueckfrage: $this->rueckfrage,
            zustand: $this->zustand,
            verweis: $verweis,
            system: $this->system,
            systemWort: $this->systemWort,
        );
    }

    /**
     * @param  array<string, mixed>  $roh
     */
    public static function aus(array $roh): ?self
    {
        $schluessel = (string) ($roh['key'] ?? '');
        $wort = (string) ($roh['label'] ?? '');

        if ($schluessel === '' || $wort === '') {
            // Ein Werkzeug ohne Schluessel ist nicht ausloesbar, eines ohne
            // Wort nicht anklickbar. Beides lautlos durchzulassen heisst,
            // einen toten Knopf in die Leiste zu stellen.
            return null;
        }

        return new self(
            schluessel: $schluessel,
            wort: $wort,
            symbol: (string) ($roh['icon'] ?? 'wrench'),
            reihenfolge: (int) ($roh['order'] ?? 100),
            gruppe: is_string($roh['group'] ?? null) && $roh['group'] !== '' ? $roh['group'] : null,
            felder: is_array($roh['fields'] ?? null) ? array_map(self::feldAus(...), array_values($roh['fields'])) : [],
            rueckfrage: is_string($roh['confirm'] ?? null) && $roh['confirm'] !== '' ? $roh['confirm'] : null,
            zustand: self::zustandAus($roh['state'] ?? null),
            verweis: is_string($roh['url'] ?? null) && $roh['url'] !== '' ? $roh['url'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->schluessel,
            'label' => $this->wort,
            'icon' => $this->symbol,
            'order' => $this->reihenfolge,
            'group' => $this->gruppe,
            // Uebersetzt, nicht durchgereicht — dieselbe Regel wie beim
            // Zustand gleich darunter. Ein Feld, dessen Wort als `wort`
            // hinausgeht und drueben als `label` gelesen wird, ist ein Feld
            // ohne Beschriftung, und das faellt erst auf, wenn jemand davor
            // sitzt.
            'fields' => array_map(self::feldHinaus(...), $this->felder),
            'confirm' => $this->rueckfrage,
            // Zurueck in die Drahtform, NICHT die inneren Namen hinaus. Sonst
            // schickt dieses System `wort`/`verweis`, das naechste liest
            // `label`/`url` — und der Zustand geht auf dem Weg verloren, ohne
            // dass etwas bricht: Die Leiste bietet dann ein Verb an, das
            // drueben schon ausgefuehrt ist.
            'state' => $this->zustand === null ? null : [
                'label' => $this->zustand['wort'],
                'url' => $this->zustand['verweis'] ?? null,
            ],
            // Der Verweis des VERBS — nicht zu verwechseln mit dem im
            // Zustand. Der eine fuehrt zum Auswaehler, der andere zu der
            // Sache, die es schon gibt.
            'url' => $this->verweis,
            'system' => $this->system,
            'system_label' => $this->systemWort,
        ];
    }

    /**
     * Woran die Oberflaeche ein Werkzeug festmacht.
     *
     * System UND Schluessel, denn der Schluessel allein ist nur im eigenen
     * System eindeutig: „Aufgabe anhaengen" kann es im Manager und in der
     * Verwaltung geben, und es sind zwei verschiedene Dinge.
     */
    public function kennung(): string
    {
        return ($this->system ?? '').':'.$this->schluessel;
    }

    /**
     * Ein Feld in die Drahtform.
     *
     * @param  array<string, mixed>  $feld
     * @return array<string, mixed>
     */
    private static function feldHinaus(array $feld): array
    {
        $hinaus = [
            'name' => (string) ($feld['name'] ?? ''),
            'label' => (string) ($feld['wort'] ?? ''),
            'type' => (string) ($feld['art'] ?? ''),
            'required' => (bool) ($feld['pflicht'] ?? false),
        ];

        if (is_array($feld['auswahl'] ?? null)) {
            $hinaus['options'] = array_map(static fn (array $w): array => [
                'value' => (string) ($w['wert'] ?? ''),
                'label' => (string) ($w['wort'] ?? ''),
            ], array_values($feld['auswahl']));
        }

        return $hinaus;
    }

    /**
     * Ein Feld aus der Drahtform.
     *
     * @return array<string, mixed>
     */
    private static function feldAus(mixed $roh): array
    {
        if (! is_array($roh)) {
            return [];
        }

        $feld = [
            'name' => (string) ($roh['name'] ?? ''),
            'wort' => (string) ($roh['label'] ?? ''),
            'art' => (string) ($roh['type'] ?? ''),
            'pflicht' => (bool) ($roh['required'] ?? false),
        ];

        if (is_array($roh['options'] ?? null)) {
            $feld['auswahl'] = array_map(static fn (mixed $w): array => [
                'wert' => (string) (is_array($w) ? ($w['value'] ?? '') : ''),
                'wort' => (string) (is_array($w) ? ($w['label'] ?? '') : ''),
            ], array_values($roh['options']));
        }

        return $feld;
    }

    /**
     * @return array{wort: string, verweis?: string|null}|null
     */
    private static function zustandAus(mixed $roh): ?array
    {
        if (! is_array($roh)) {
            return null;
        }

        $wort = (string) ($roh['label'] ?? '');

        if ($wort === '') {
            return null;
        }

        return [
            'wort' => $wort,
            'verweis' => is_string($roh['url'] ?? null) && $roh['url'] !== '' ? $roh['url'] : null,
        ];
    }
}
