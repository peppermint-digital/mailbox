<?php

namespace Peppermint\Mailbox;

/**
 * Was eine brauchbare Nachrichten-Kennung ist — und was keine.
 *
 * IMAP zaehlt uids, JMAP vergibt Zeichenketten (`bpyaaaal1`). Das ist der EINE
 * Unterschied, den {@see Contracts\Mailbox} zulaesst, und genau deshalb muss
 * die Frage „kann ich damit ans Postfach gehen?" an EINER Stelle beantwortet
 * werden.
 *
 * ## Warum das kein `> 0` mehr sein darf
 *
 * Der Riegel stammt aus einer Zeit, in der jede Kennung eine Zahl war:
 *
 *     if ($uid <= 0) { ... }
 *
 * Eine JMAP-Zeichenkette faellt da durch — und das Ergebnis war am 21.09.2026
 * ein Postfach, in dem sich keine Nachricht oeffnen liess. Die Regel selbst
 * war richtig: `UID FETCH 0` ist nicht „es passiert nichts", sondern „die
 * Nachrichtenmenge ist ungueltig". Nur ihre Annahme war zu eng.
 *
 * Das Gegenstueck auf der Oberflaechenseite heisst `hasUsableUid()` und sagt
 * dasselbe — wer eines aendert, aendert beide.
 */
class Handles
{
    /**
     * Laesst sich mit dieser Kennung eine Nachricht holen?
     *
     * - Zahl: nur echt groesser als null. 0 und negative Werte sind keine
     *   Kennung, sondern das Ergebnis eines fehlenden Wertes.
     * - Zeichenkette, die eine Zahl ist: dieselbe Regel — `"0"` ist so wenig
     *   eine Kennung wie `0`. Routenparameter kommen immer als Zeichenkette.
     * - Sonstige Zeichenkette: brauchbar, sobald sie nicht leer ist. Mehr
     *   laesst sich ueber eine JMAP-Kennung nicht sagen, ohne den Server zu
     *   fragen — und das ist genau der Aufruf, den dieser Riegel schuetzt.
     */
    public static function usable(int|string|null $handle): bool
    {
        if ($handle === null) {
            return false;
        }

        if (is_int($handle)) {
            return $handle > 0;
        }

        $handle = trim($handle);

        if ($handle === '') {
            return false;
        }

        // `"0"` und `"-1"` sind Zahlen in Zeichenketten-Kleidung und werden
        // wie Zahlen beurteilt. Alles andere ist eine JMAP-Kennung.
        if (is_numeric($handle)) {
            return (float) $handle > 0;
        }

        return true;
    }
}
