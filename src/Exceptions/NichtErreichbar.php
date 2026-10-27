<?php

namespace Peppermint\Mailbox\Exceptions;

use Peppermint\Mailbox\Imap\RetryPolicy;
use Throwable;

/**
 * Ist dieser Fehler „das Postfach antwortet nicht"?
 *
 * ## Warum diese Frage einen Ort braucht
 *
 * Ein Produkt muss zwei Lagen auseinanderhalten, weil sie verschiedene
 * naechste Schritte bedeuten:
 *
 *   502 — das Postfach ist nicht erreichbar  → abwarten
 *   422 — die Anfrage geht so nicht          → Eingabe aendern
 *
 * Bis zum 20.10.2026 entschied das im Projekt-Manager ein `catch` auf
 * `DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException`. Das war
 * richtig und unvollstaendig: An einem JMAP-Postfach fliegt diese Ausnahme
 * NIE. Dort landete ein echter Verbindungsabbruch im `\Throwable`-Zweig und
 * kam als 422 beim Menschen an — „aendere deine Eingabe", wo nichts zu aendern
 * war.
 *
 * Aufgefallen durch den Waechter `ZugangsdatenZentralTest` des Managers, der
 * genau davor warnt: Wer im Produkt den Transport nennt, hat eine Stelle
 * gebaut, die nur fuer einen der beiden Transporte stimmt. Gemeldet von der
 * Session calendar-26.
 *
 * ## Warum Nachrichten und nicht Typen
 *
 * Das Paket spricht zwei Transporte, und darunter liegen PHP-Streams, eine
 * IMAP-Bibliothek, ein HTTP-Klient und der Server. Jede Schicht hat ihre
 * eigenen Ausnahmetypen, und die meisten sind `RuntimeException`.
 *
 * Deshalb dieselbe Grundlage, auf der auch die Wiederholung entscheidet:
 * {@see RetryPolicy::TRANSIENT}. Ein Fehler, der einen zweiten Versuch wert
 * ist, ist genau einer, bei dem die Leitung das Problem war — und das ist
 * dieselbe Frage von der anderen Seite. Zwei Listen daneben wuerden
 * auseinanderlaufen, und dann wartet der Mensch bei einem Fehler, den das
 * System still wiederholt haette.
 *
 * ## Was ausdruecklich NICHT darunter faellt
 *
 * „Der Ordner existiert bereits", „Folder not found", „die Nachricht liegt
 * nicht in diesem Ordner". Das sind Antworten des Servers, keine fehlenden
 * Antworten. Ein Test haelt das fest — ohne ihn waere die Versuchung gross,
 * die Liste zu verbreitern, bis alles 502 ist.
 */
final class NichtErreichbar
{
    /**
     * Ausnahmetypen, die „nicht erreichbar" ohne Blick in die Nachricht sagen.
     *
     * Als Zeichenketten und nicht als `instanceof`: Die IMAP-Bibliothek ist
     * eine Abhaengigkeit des Transports, nicht dieser Klasse. Ein `use` darauf
     * waere eine Kopplung, die ein JMAP-only-Einsatz mitschleppen muesste.
     *
     * @var list<class-string>
     */
    private const TYPEN = [
        MissingAccessToken::class,
        'DirectoryTree\\ImapEngine\\Exceptions\\ImapConnectionFailedException',
        'DirectoryTree\\ImapEngine\\Exceptions\\ConnectionFailedException',
        'DirectoryTree\\ImapEngine\\Exceptions\\ConnectionClosedException',
    ];

    public static function trifftZu(Throwable $fehler): bool
    {
        foreach (self::TYPEN as $typ) {
            if (is_a($fehler, $typ)) {
                return true;
            }
        }

        /*
         * Auch die URSACHEN ansehen.
         *
         * Guzzle und der HTTP-Klient von Laravel verpacken einen abgebrochenen
         * Strom gern in eine eigene Ausnahme; die Nachricht der aeusseren sagt
         * dann „Request failed" und verraet nichts. Ohne diese Schleife
         * entscheidet die Verpackung, nicht der Fehler.
         */
        for ($aktuell = $fehler; $aktuell !== null; $aktuell = $aktuell->getPrevious()) {
            $nachricht = mb_strtolower($aktuell->getMessage());

            foreach (RetryPolicy::TRANSIENT as $begriff) {
                if (str_contains($nachricht, $begriff)) {
                    return true;
                }
            }
        }

        return false;
    }
}
