<?php

namespace Peppermint\Mailbox\Archive;

/**
 * Wohin die Rohfassungen geschrieben werden.
 *
 * ## Warum nicht einfach `Storage::disk(...)`
 *
 * Weil das Paket nicht wissen kann, wie die Ablage des Produkts heisst, wo sie
 * liegt und ob sie ueberhaupt eine Laravel-Ablage ist. Ein fest verdrahteter
 * Plattenname waere genau die Sorte Annahme, die bei der ersten fremden
 * Installation bricht — und dann mit einer Fehlermeldung, die von Dateisystemen
 * spricht statt von Mail.
 *
 * ## Warum ueberhaupt Dateien
 *
 * Ein Anhang von zwanzig Megabyte in der Datenbank macht jede Sicherung und
 * jede Abfrage teurer, ohne dass je darin gesucht wuerde. In der Datenbank
 * steht der Pfad und die Pruefsumme; die Bytes liegen daneben.
 */
interface Ablage
{
    /**
     * Legt die Bytes unter diesem Pfad ab.
     *
     * Der Pfad ist RELATIV zur Wurzel der Ablage — sonst haengt der gesamte
     * Bestand an einem Verzeichnisnamen, und ein Umzug bedeutet, jede Zeile in
     * der Datenbank umzuschreiben.
     *
     * Eine vorhandene Datei wird NICHT ueberschrieben: Dieselbe Nachricht ein
     * zweites Mal zu holen ist normal (ein Lauf lief doppelt, ein Ordner wurde
     * neu erfasst) — sie neu zu schreiben waere eine Aenderung an etwas, das
     * unveraendert bleiben soll.
     */
    public function ablegen(string $pfad, string $bytes): void;

    /**
     * Liegt dort schon etwas?
     */
    public function vorhanden(string $pfad): bool;

    /**
     * Die Bytes zurueck — oder null, wenn dort nichts liegt.
     */
    public function lesen(string $pfad): ?string;
}
