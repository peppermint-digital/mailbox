<?php

namespace Peppermint\Mailbox\Exceptions;

use Throwable;

/**
 * Ist dieser Fehler „der Server hat die Anmeldung abgelehnt"?
 *
 * ## Warum diese Frage neben {@see NichtErreichbar} steht
 *
 * Drei Lagen, drei verschiedene nächste Schritte:
 *
 *   Leitung weg      → noch einmal versuchen  ({@see \Peppermint\Mailbox\Imap\RetryPolicy})
 *   Nicht erreichbar → abwarten               ({@see NichtErreichbar})
 *   Anmeldung abgelehnt → die Zugangsdaten sind alt, NEU HOLEN und EINMAL wiederholen
 *
 * Die dritte fehlte. Sie landete bisher im allgemeinen Fehlerzweig und kam
 * als „falsches Passwort" beim Menschen an, obwohl nur ein zwischengespeichertes
 * Zugriffstoken abgelaufen war — Bug #1098.
 *
 * ## Warum das nicht wiederholt werden darf wie ein Leitungsfehler
 *
 * `RetryPolicy` erklärt es: Eine abgelehnte Anmeldung ist eine **Antwort**,
 * keine fehlende Antwort. Sie fünfmal zu wiederholen macht aus einem Fehler,
 * der in einer Sekunde gemeldet wäre, eine halbe Minute Schweigen mit
 * demselben Ergebnis.
 *
 * Deshalb hängt an dieser Klasse genau **ein** zweiter Versuch, und auch der
 * nur, wenn vorher etwas passiert ist, das die Lage ändern kann: Der
 * gespeicherte Kontenstand wird verworfen und neu geholt. Ohne diesen Schritt
 * wäre der zweite Versuch dieselbe Frage mit denselben Daten.
 *
 * ## Warum Nachrichten und nicht Typen
 *
 * Dieselbe Begründung wie bei {@see NichtErreichbar}: Das Paket spricht zwei
 * Transporte, darunter liegen eine IMAP-Bibliothek und ein HTTP-Klient, und
 * fast alles davon ist `RuntimeException`. Der IMAP-Server antwortet im
 * Klartext (`NO AUTHENTICATE failed`), der JMAP-Server mit `401`.
 *
 * ## Was ausdrücklich NICHT darunter fällt
 *
 * - Alles, was {@see NichtErreichbar} schon beantwortet. Ein abgebrochener
 *   Strom ist keine Ablehnung, und `MissingAccessToken` heißt, dass die Mitte
 *   gar nichts zu geben hatte — neu zu fragen brächte dasselbe Nichts.
 * - **HTTP 403.** Das ist „du darfst das nicht", nicht „wer bist du". Ein
 *   frisches Token ändert daran nichts, und ein zweiter Versuch wäre eine
 *   Wiederholung ohne Aussicht.
 */
final class AnmeldungAbgelehnt
{
    /**
     * Wortlaute, an denen eine abgelehnte Anmeldung zu erkennen ist.
     *
     * Kleingeschrieben verglichen, weil die Formulierung aus einem halben
     * Dutzend Schichten kommt.
     *
     * @var list<string>
     */
    private const BEGRIFFE = [
        'authenticate failed',
        'authentication failed',
        'authenticationfailed',
        'login failed',
        'invalid credentials',
        'http 401',
        'unauthorized',
    ];

    public static function trifftZu(Throwable $fehler): bool
    {
        /*
         * Die Leitung hat Vorrang.
         *
         * Ein abgebrochener Strom waehrend der Anmeldung kann eine Meldung
         * tragen, in der beides vorkommt. Wird er hier als Ablehnung gelesen,
         * verwirft das Produkt einen voellig gesunden Kontenstand — und der
         * naechste Aufruf holt ihn teuer neu, obwohl nur das Netz gezuckt hat.
         */
        if (NichtErreichbar::trifftZu($fehler)) {
            return false;
        }

        for ($aktuell = $fehler; $aktuell !== null; $aktuell = $aktuell->getPrevious()) {
            $nachricht = mb_strtolower($aktuell->getMessage());

            foreach (self::BEGRIFFE as $begriff) {
                if (str_contains($nachricht, $begriff)) {
                    return true;
                }
            }
        }

        return false;
    }
}
