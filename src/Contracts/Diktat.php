<?php

namespace Peppermint\Mailbox\Contracts;

/**
 * Gesprochenes zu Text machen.
 *
 * Dieselbe Naht wie {@see Schreibhilfe}: Das Paket bringt den Weg mit, nicht
 * den Dienst. Wer kein Spracherkennungsmodell hat, bindet nichts — und dann
 * zeigt die Oberflaeche den Knopf gar nicht erst.
 *
 * **Das ist kein Schoenheitsgewinn, sondern dieselbe Regel wie bei den
 * Mail-Knoepfen ohne Sendeweg:** Ein Knopf ohne Wirkung sieht aus wie ein
 * Defekt, nicht wie eine fehlende Funktion.
 *
 * ## Die Aufnahme wird nirgends abgelegt
 *
 * Sie reist durch und wird zu Text. Was gesprochen wurde, gehoert dem
 * Menschen, der es gesprochen hat — eine Audiodatei, die „nur fuer den Fall"
 * liegen bleibt, ist ein Mitschnitt und war nie gemeint.
 */
interface Diktat
{
    /**
     * Eine Aufnahme abtippen.
     *
     * @param  string  $pfad  Pfad zur hochgeladenen Datei
     * @param  string  $mimeTyp  was der Browser aufgenommen hat (`audio/webm` …)
     * @return string das Transkript
     *
     * @throws \RuntimeException wenn der Dienst nicht antwortet
     */
    public function abtippen(string $pfad, string $mimeTyp): string;
}
