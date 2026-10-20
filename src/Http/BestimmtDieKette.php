<?php

namespace Peppermint\Mailbox\Http;

use Peppermint\Mailbox\Threading\ThreadKey;

/**
 * Welche Unterhaltung gemeint ist — EINMAL, fuer alle Merkmale.
 *
 * ## Warum ein eigenes Merkmal und nicht je Stelle
 *
 * Weil es vorher je Stelle war, und das hatte eine Folge, die niemandem
 * auffiel: {@see HandlesAssignments} fragte das Produkt
 * (`threadKeyFor()`), {@see HandlesSnoozes} rechnete selbst
 * (`ThreadKey::fromHeaders()`). Im Projekt-Manager, der ein
 * Kopfzeilen-Verzeichnis fuehrt und die Wurzel auch ohne `In-Reply-To`
 * findet, lieferten die beiden Wege damit VERSCHIEDENE Ketten fuer dieselbe
 * Unterhaltung.
 *
 * Sichtbar wird das nie als Fehler. Es sieht so aus: Die Zuweisung haengt am
 * richtigen Faden, das Schlummern an einem anderen — und eine Mail, die man
 * weggelegt hat, kommt mit der naechsten Antwort wieder, waehrend die
 * Zustaendigkeit bleibt. Der Docblock des Managers notierte es sogar
 * („vier weitere Stellen benutzen die alte Form"), ohne die Folge zu ziehen.
 *
 * Die Regel dagegen steht im Projektgedaechtnis: **Zwei Stellen, die
 * „dieselbe" Entscheidung treffen, muessen dieselbe Funktion rufen — nicht
 * dieselbe Bedingung enthalten.** Eine Regel, die zweimal geschrieben steht,
 * ist nicht eine Regel; sie ist zwei, die heute uebereinstimmen.
 *
 * ## Dass mehrere Merkmale dasselbe Merkmal benutzen, ist Absicht
 *
 * Ein Controller, der Zuweisungen UND Schlummern UND Werkzeuge fuehrt, bindet
 * `threadKeyFor()` ueber drei Wege ein — aber aus EINER Quelle. PHP sieht
 * dieselbe Methode aus demselben Merkmal und beschwert sich zu Recht nicht.
 * Stuende sie in jedem der drei, waere es ein Fatal beim Booten; und genau
 * das ist der Grund, warum die Dopplung vorher ueberhaupt entstand.
 */
trait BestimmtDieKette
{
    /**
     * Welche Kette gemeint ist.
     *
     * Standardmaessig aus den Kopfzeilen, die der Browser mitschickt — mehr
     * weiss das Paket nicht.
     *
     * Ein Produkt kann mehr wissen. Der Projekt-Manager fuehrt ein
     * Kopfzeilen-Verzeichnis und loest die Kette dagegen auf: Kommt eine
     * Anfrage ohne `In-Reply-To`, findet er trotzdem die Wurzel, waehrend das
     * Paket auf die Nachricht selbst zurueckfaellt. Beides ist richtig — nur
     * mit verschieden viel Wissen.
     *
     * Aufgefallen ist das an einem roten Test bei der Umstellung des Managers.
     * Ohne diesen Haken waere die Zuweisung dort danach an der falschen Kette
     * gehangen: kein Fehler, keine Meldung, nur eine Unterhaltung, die sich in
     * zwei teilt.
     *
     * @param  array<string, mixed>  $daten
     */
    protected function threadKeyFor(int $account, array $daten): ?string
    {
        return ThreadKey::fromHeaders(
            (string) ($daten['message_id'] ?? ''),
            $daten['in_reply_to'] ?? null,
            $daten['references'] ?? null,
        );
    }
}
