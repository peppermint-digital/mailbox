<?php

namespace Peppermint\Mailbox\Archive;

use DateTimeInterface;

/**
 * Wo die Rohfassung einer Nachricht liegt.
 *
 * ## Nach Jahr und Monat, nicht flach
 *
 * Ein Verzeichnis mit zweihunderttausend Dateien ist auf den meisten
 * Dateisystemen noch gueltig und schon unbenutzbar: Jedes `ls`, jede Sicherung,
 * jeder Virenscanner liest das ganze Verzeichnis. Jahr und Monat halten die
 * Zahl je Verzeichnis im Bereich von Hunderten.
 *
 * ## Der Dateiname ist der Hash, nicht die Message-ID
 *
 * Eine Message-ID darf fast jedes Zeichen enthalten — Schraegstriche,
 * Doppelpunkte, Leerzeichen, alles ueber 255 Zeichen. Sie als Dateinamen zu
 * benutzen hiesse, sie zu bereinigen, und eine bereinigte Kennung ist keine
 * mehr: Zwei verschiedene Nachrichten koennten auf denselben Namen fallen.
 *
 * Der Hash ist fest lang, kollisionsfrei und enthaelt keine Sonderzeichen.
 * Welche Nachricht dahintersteckt, steht in der Datenbank — dort gehoert es
 * hin.
 */
final class Pfad
{
    public static function fuer(int $accountId, string $messageIdHash, ?DateTimeInterface $datum = null): string
    {
        // Ohne Datum in ein eigenes Verzeichnis: Eine Nachricht ohne
        // auswertbares Datum gibt es, und sie soll nicht im Januar 1970
        // landen — das sieht aus wie ein Fehler in den Daten und ist einer im
        // Ablegen.
        $zeit = $datum !== null
            ? $datum->format('Y/m')
            : 'ohne-datum';

        return "mail/{$accountId}/{$zeit}/{$messageIdHash}.eml";
    }
}
