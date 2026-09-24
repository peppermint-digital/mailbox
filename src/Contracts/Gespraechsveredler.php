<?php

namespace Peppermint\Mailbox\Contracts;

/**
 * Macht aus einer Nachricht das, was ein Mensch daraus lesen wuerde.
 *
 * ## Warum es dafuer eine Schnittstelle braucht
 *
 * {@see \Peppermint\Mailbox\Content\Gespraechstext} trennt Zitat, Signatur und
 * Fusszeile ab — mit Regeln, und Regeln reichen weit. Sie reichen nicht bis
 * hierhin:
 *
 *     Auftragsnummer
 *     VAU-26-110589
 *     Lieferscheinnummer
 *     VLI-26-138100
 *     Transportunternehmen
 *     UPS
 *
 * Das ist eine HTML-Tabelle, zu Text gemacht. Es gibt keinen Fliesstext, den
 * man freilegen koennte — die Nachricht IST die Tabelle. Eine Heuristik kann
 * daraus nichts machen, weil nichts zum Abtrennen da ist, sondern etwas zum
 * VERSTEHEN.
 *
 * Genau das kann ein Sprachmodell, und genau das gehoert nicht ins Paket: Es
 * soll ohne Modell laufen, ohne Schluessel, ohne fremden Dienst. Also die
 * Moeglichkeit hier, die Entscheidung beim Produkt.
 *
 * ## Was eine Umsetzung zurueckgeben soll
 *
 * Den Kern der Nachricht in normalen Saetzen — das, was jemand einem Kollegen
 * sagen wuerde, wenn er die Mail weiterreicht. Aus dem Beispiel oben etwa:
 * „Ihre Sendung VAU-26-110589 ist mit UPS unterwegs, Trackingnummer
 * 1Z61FW756862359328, 1 Packstueck."
 *
 * `null` heisst „kann ich nicht" oder „will ich nicht" — dann bleibt es bei
 * dem, was die Regeln hergeben. Das ist ausdruecklich vorgesehen: Ein Modell,
 * das gerade nicht antwortet, darf keinen Verlauf verhindern.
 *
 * ## Die Fassung wandert mit
 *
 * Ein Modell aendert sich, eine Anweisung wird besser. Ohne Kennzeichen am
 * Ergebnis liesse sich spaeter nicht sagen, was von welcher Fassung stammt —
 * und Nacharbeit waere dann alles oder nichts.
 */
interface Gespraechsveredler
{
    /**
     * @param  string  $bereinigt  Was die Regeln uebrig gelassen haben
     * @param  array<string, mixed>  $kopf  Betreff, Absender, Datum — Zusammenhang fuer das Modell
     * @return string|null Der lesbare Kern, oder null
     */
    public function veredeln(string $bereinigt, array $kopf): ?string;

    /**
     * Womit das gemacht wurde — Modell und Fassung der Anweisung.
     *
     * Steht am Ergebnis, damit „alles neu rechnen" nie die einzige Antwort auf
     * eine Verbesserung ist.
     */
    public function kennzeichen(): string;
}
