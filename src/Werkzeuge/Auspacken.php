<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Peppermint\AiBrainBridge\Verbund\Huelle;

/**
 * Die Antworten der Werkzeug-Faehigkeiten auspacken.
 *
 * Die Huellen-Logik fuer LISTEN steht in {@see Huelle} — sie ist dieselbe
 * fuer jedes Werkzeug am Komm-Layer, und eine zweite Kopie davon veraltet
 * still.
 */
final class Auspacken
{
    /** Die Huellen, in denen eine Nutzlast ankommen kann. */
    private const HUELLEN = ['data', 'structuredContent', 'content', 'result', 'antwort'];

    /**
     * Die Liste der Werkzeuge eines Systems.
     *
     * @param  array<string, mixed>  $antwort
     * @return array{tools: array<int, array<string, mixed>>, failed: array<int, string>}
     */
    public static function liste(array $antwort): array
    {
        $huelle = Huelle::schaelen($antwort, 'tools');

        return [
            'tools' => Huelle::zeilen($huelle, 'tools'),
            // Werkzeuge, die DRUEBEN nicht geliefert werden konnten. Sie
            // fehlen in der Leiste, und ohne diese Angabe faellt das
            // niemandem auf.
            'failed' => Huelle::woerter($huelle, 'failed'),
        ];
    }

    /**
     * Das Ergebnis eines ausgeloesten Werkzeugs.
     *
     * ## Zwei Ebenen, die nicht verwechselt werden duerfen
     *
     * Der Gateway sagt mit `ok`, ob die ZUSTELLUNG geklappt hat. Ob der
     * VORGANG geklappt hat, sagt das Werkzeug selbst, in seiner eigenen
     * Antwort. Ein zugestellter Auftrag, den das Zielsystem abgelehnt hat,
     * ist Gateway-`ok: true` und Werkzeug-`ok: false` — wer nur die aeussere
     * Schale liest, meldet dem Nutzer Erfolg fuer etwas, das nicht passiert
     * ist.
     *
     * ## Warum {@see Huelle} hier nicht passt
     *
     * `Huelle::schaelen()` erkennt die Nutzlast an einem Schluessel, der ein
     * ARRAY enthaelt. Das Merkmal eines Ergebnisses ist aber `ok` — ein
     * Wahrheitswert. Deshalb hier ein eigener Durchlauf, mit derselben
     * Huellen-Liste und derselben Haltung.
     *
     * ## Keine Antwort ist kein Erfolg
     *
     * Findet sich nirgends ein `ok`, ist das Ergebnis ein Fehlschlag — nicht
     * ein Erfolg ohne Text. Genau hier wuerde aus „beauftragt" sonst
     * „erledigt": Die Oberflaeche zeigt einen gruenen Haken, im Zielsystem
     * ist nichts entstanden, und gesucht wird es erst Tage spaeter.
     *
     * @param  array<string, mixed>  $antwort
     * @return array<string, mixed>
     */
    public static function ergebnis(array $antwort): array
    {
        if (($antwort['ok'] ?? null) === false) {
            return [
                'ok' => false,
                'message' => (string) ($antwort['error'] ?? $antwort['message'] ?? 'Der Auftrag kam nicht an.'),
            ];
        }

        foreach (self::HUELLEN as $schluessel) {
            $kandidat = $antwort[$schluessel] ?? null;

            if (is_array($kandidat) && array_key_exists('ok', $kandidat)) {
                return $kandidat;
            }
        }

        /*
         * Die nackte Form: Das Werkzeug wurde direkt gerufen, nicht ueber den
         * Gateway. Dann traegt die Antwort selbst das `ok`, und ihr `data`
         * ist nicht die Huelle, sondern der Inhalt.
         *
         * `product` ist das Erkennungszeichen des Gateways — es steht in
         * JEDER seiner Antworten. Ohne diese Abgrenzung wuerde eine
         * Gateway-Antwort, deren Nutzlast unterwegs verlorenging, hier als
         * Ergebnis durchgehen: `ok: true` und ein leerer Satz, also ein
         * gruener Haken fuer etwas, das nie passiert ist.
         */
        if (array_key_exists('ok', $antwort) && ! array_key_exists('product', $antwort)) {
            return $antwort;
        }

        return [
            'ok' => false,
            'message' => 'Das andere System hat nicht gesagt, ob der Vorgang geklappt hat.',
        ];
    }
}
