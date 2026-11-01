<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Exceptions\AnhangNichtVerwendbar;

/**
 * Die Mail eines Vorgangs, GEFUNDEN und geprueft (#9145).
 *
 * Was {@see VorgangsNachricht::finden()} herausgibt: der Ordner, die Kennung
 * und die Nachricht selbst — und die Zusicherung, dass ihre `Message-ID` die
 * des Vorgangs ist. Ohne diese Zusicherung waere das Objekt nutzlos: Ordner
 * und Kennung sind MITGETEILT (das fragende System schickt sie in
 * {@see Vorgang::$zusatz}), und was mitgeteilt ist, ist eine Behauptung.
 *
 * ## Warum der Anhang hier herauskommt und nicht beim Produkt
 *
 * Das ist die Falle, um die es in dieser Aufgabe geht. Die Feldart `anhang`
 * liefert den DATEINAMEN; die Produktrouten von heute arbeiten mit der
 * STELLE (`…/attachment/{index}/invoice`). Wer selbst umrechnet, tut es in
 * der Reihenfolge der Mail, die er gerade sieht — und bei einer Mail, die ein
 * anderes System geholt hat, ist das eine Annahme. Wird sie falsch, wird der
 * falsche Anhang verbucht, und niemand merkt es.
 *
 * Also rechnet es EINE Stelle, und zwar in der Reihenfolge der Mail, die
 * gerade geprueft wurde. Drei Produkte, die dasselbe umrechnen, sind drei
 * Regeln, die heute uebereinstimmen.
 */
final class Nachrichtenfund
{
    /**
     * @param  array<string, mixed>  $nachricht  wie {@see Mailbox::message()} sie herausgibt
     */
    public function __construct(
        private readonly Mailbox $postfach,
        public readonly string $ordner,
        public readonly int|string $kennung,
        public readonly array $nachricht,
    ) {}

    /**
     * Ein Anhang dieser Mail — ueber den NAMEN, mit seinen Bytes.
     *
     * Die Stelle kommt aus der Liste, die zu DIESER geholten Mail gehoert
     * (`message()['attachments']`), und nicht aus einer Zaehlung des
     * Aufrufers. Der Unterschied ist der ganze Zweck dieser Methode.
     *
     * @return array{filename: string, mime_type: string, contents: string}
     *
     * @throws AnhangNichtVerwendbar wenn der Name fehlt, mehrfach vorkommt oder
     *                               die Bytes nicht mehr zu holen sind
     */
    public function anhang(string $dateiname): array
    {
        $treffer = $this->stellenFuer($dateiname);

        if ($treffer === []) {
            throw new AnhangNichtVerwendbar("Diese Mail hat keinen Anhang „{$dateiname}“.");
        }

        if (count($treffer) > 1) {
            /*
             * Zwei gleichnamige Anhaenge. Die Leiste hat sie beide als
             * dieselbe Zeile angeboten (sie schluesselt nach Dateinamen) —
             * also weiss niemand, welcher gemeint war, auch der Mensch davor
             * nicht. Einen davon zu nehmen ist genau die Wette, gegen die
             * der Name statt der Stelle geliefert wird.
             */
            throw new AnhangNichtVerwendbar(
                "Zwei Anhaenge dieser Mail heissen „{$dateiname}“ — welcher gemeint ist, laesst sich nicht entscheiden."
            );
        }

        $anhang = $this->postfach->attachment($this->ordner, $this->kennung, $treffer[0]);

        if ($anhang === null) {
            // Die Stelle stand in der Liste und gibt keine Bytes her. Das ist
            // kein Programmfehler — die Mail kann zwischen den beiden
            // Abrufen verschwunden sein.
            throw new AnhangNichtVerwendbar("Der Anhang „{$dateiname}“ liess sich nicht holen.");
        }

        return $anhang;
    }

    /**
     * Mehrere Anhaenge auf einmal — fuer ein Feld mit `mehrfach`.
     *
     * Eine LISTE von Namen herein, eine Liste von Anhaengen heraus, in der
     * Reihenfolge der Namen. Scheitert einer, scheitert der ganze Aufruf:
     * Eine Aufgabe mit zwei von drei Dateien daran sieht vollstaendig aus und
     * ist es nicht.
     *
     * @param  list<string>  $dateinamen
     * @return list<array{filename: string, mime_type: string, contents: string}>
     *
     * @throws AnhangNichtVerwendbar
     */
    public function anhaenge(array $dateinamen): array
    {
        return array_values(array_map(fn (string $name): array => $this->anhang($name), $dateinamen));
    }

    /**
     * Die Stellen, an denen ein Anhang dieses Namens steht.
     *
     * Ueber `message()['attachments']` und damit ueber dieselbe Zaehlung, die
     * {@see Mailbox::attachment()} erwartet und die die Leiste angezeigt hat.
     *
     * @return list<int>
     */
    private function stellenFuer(string $dateiname): array
    {
        $stellen = [];
        $liste = $this->nachricht['attachments'] ?? [];

        if (! is_array($liste)) {
            return [];
        }

        foreach ($liste as $anhang) {
            if (! is_array($anhang) || ! isset($anhang['index'])) {
                continue;
            }

            if ((string) ($anhang['filename'] ?? '') === $dateiname) {
                $stellen[] = (int) $anhang['index'];
            }
        }

        return $stellen;
    }
}
