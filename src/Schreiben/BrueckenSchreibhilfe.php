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
 * Bruecke zu sich selbst waere ein Umweg mit Netzverkehr. Dort gehoert der
 * eigene Dienst direkt gebunden — dieselbe Unterscheidung, die
 * {@see BridgeWerkzeugquelle} fuer die Werkzeuge schon trifft.
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
     * Der Text aus dem Ergebnis — in der Reihenfolge, in der er vorkommt.
     *
     * Eine blanke Zeichenkette IST die Antwort; sonst steckt sie unter
     * `result` oder `text`. Genau diese drei Faelle behandelt der Manager seit
     * jeher, und sie sind nicht geraten, sondern gewachsen.
     */
    private function herausholen(mixed $ergebnis): ?string
    {
        if (is_string($ergebnis) && trim($ergebnis) !== '') {
            return $ergebnis;
        }

        $liste = Kanalklient::entpacken($ergebnis);
        $text = $liste['result'] ?? $liste['text'] ?? null;

        return is_string($text) && trim($text) !== '' ? $text : null;
    }
}
