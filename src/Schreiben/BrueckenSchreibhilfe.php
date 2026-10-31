<?php

namespace Peppermint\Mailbox\Schreiben;

use Peppermint\Mailbox\Contracts\Schreibhilfe;
use RuntimeException;

/**
 * Der Schreibassistent ueber die Bruecke — die Vorgabe fuer alle, die sie haben.
 *
 * Drei der vier Produkte tragen `peppermint/ai-brain-bridge` ohnehin. Sie
 * bekommen damit denselben Assistenten, den der Projekt-Manager seit je hat,
 * durch EINE Zeile Bindung statt durch einen eigenen Dienst — dahinter steht
 * dasselbe Brain und derselbe Kanal.
 *
 * **AI Brain selbst bindet das nicht so.** Es ist die Nabe; der Weg ueber eine
 * Bruecke zu sich selbst waere ein Umweg mit Netzverkehr. Dieselbe
 * Unterscheidung, die {@see BridgeWerkzeugquelle} fuer die Werkzeuge schon
 * trifft.
 *
 * Es braucht dort aber KEINE eigene Schreibhilfe, und das ist seit dem
 * 31.10.2026 der Punkt: Es bringt nur eine eigene {@see Kanalstrecke} mit und
 * baut diese Klasse damit —
 *
 *     new BrueckenSchreibhilfe(new Kanalklient(strecke: new EigeneStrecke))
 *
 * Dann kommen Pollen, Frist, `needs_input` und das Entpacken aus dem Paket,
 * und das Produkt hat nur den Transport beigetragen.
 *
 * Der Name sagt also, woher die Klasse KOMMT, nicht womit sie laeuft. Ihn zu
 * aendern waere ehrlicher und kostet drei Produkte eine Anpassung an einer
 * Bindung, die funktioniert — das ist es nicht wert.
 */
class BrueckenSchreibhilfe implements Schreibhilfe
{
    /** Derselbe Kanal, den der Projekt-Manager seit je benutzt. */
    public const KANAL = 'spell-check';

    public function __construct(private readonly Kanalklient $kanal = new Kanalklient) {}

    /**
     * Ist das hier benutzbar?
     *
     * Ein Produkt bindet nur dann — und zeigt sonst den Knopf gar nicht erst.
     */
    public static function verfuegbar(): bool
    {
        return Kanalklient::eingerichtet();
    }

    /**
     * Dasselbe, aber fuer den Weg, den DIESE Instanz nimmt.
     *
     * {@see verfuegbar()} fragt die Bruecke, weil ein Produkt die Frage
     * stellt, bevor es eine Instanz hat. Wer eine eigene Strecke mitbringt,
     * muss hier fragen: Die statische Fassung wuerde `false` sagen und einen
     * Assistenten verstecken, den es gibt. Genau dieser Fall war der Anlass
     * (AI Brain, 31.10.2026).
     */
    public function nutzbar(): bool
    {
        return $this->kanal->nutzbar();
    }

    public function verarbeiten(string $text, string $modus, ?string $stil = null): string
    {
        $ergebnis = $this->kanal->fragen(self::KANAL, array_filter([
            'text' => $text,
            'mode' => $modus,
            'style' => $stil,
        ], static fn ($wert) => $wert !== null));

        $neu = $this->herausholen($ergebnis);

        if ($neu === null) {
            /*
             * Lieber eine Absage als ein leerer Editor. Gaebe es hier eine
             * leere Zeichenkette zurueck, ersetzte die Oberflaeche den Text
             * des Menschen durch nichts — und der ist dann weg.
             */
            throw new RuntimeException('Der Schreibassistent hat nichts zurückgegeben.');
        }

        return $neu;
    }

    /**
     * Der Text aus dem Ergebnis.
     *
     * Eine blanke Zeichenkette IST die Antwort; sonst steckt sie unter
     * `result` oder `text`. Genau diese drei Faelle behandelt der Manager seit
     * jeher, und sie sind nicht geraten, sondern gewachsen.
     *
     * ## Die Reihenfolge, und warum sie seit dem 31.10.2026 umgedreht ist
     *
     * Vorher stand die blanke Zeichenkette ZUERST. Damit war der
     * Markdown-Fall in {@see Kanalklient::entpacken()} von hier aus
     * unerreichbar: Ein Ergebnis wie
     *
     *     ```json
     *     {"result":"Guten Tag"}
     *     ```
     *
     * ist eine nicht-leere Zeichenkette, gewann also sofort — und landete
     * mitsamt Zaun und Klammern im Mailtext des Menschen. Aufgefallen beim
     * Zusichern der Kanalstrecke, nicht im Betrieb; gemeldet hat es niemand,
     * weil es wie eine Laune des Modells aussieht.
     *
     * Jetzt wird erst entpackt und nur die HUELLE verworfen: Was sich zu
     * einer Liste mit `result` oder `text` entpacken laesst, war eine Huelle.
     * Alles andere ist die Antwort selbst und geht unveraendert durch — auch
     * ein Text, der zufaellig gueltiges JSON ist, denn dem fehlen diese
     * Schluessel.
     */
    private function herausholen(mixed $ergebnis): ?string
    {
        $liste = Kanalklient::entpacken($ergebnis);
        $text = $liste['result'] ?? $liste['text'] ?? null;

        if (is_string($text) && trim($text) !== '') {
            return $text;
        }

        if (is_string($ergebnis) && trim($ergebnis) !== '') {
            return $ergebnis;
        }

        return null;
    }
}
