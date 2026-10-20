<?php

namespace Peppermint\Mailbox\Folders;

use Illuminate\Support\Facades\Cache;

/**
 * Die Ordnerliste eines Postfachs — gemerkt statt jedes Mal geholt.
 *
 * ## Warum es das gibt
 *
 * Gemessen am 23.09.2026 live im CRM (Aufgabe #6119): Die Ordnerliste kostete
 * **485 ms**, beim zweiten Aufruf 525 ms. Der zweite war also so teuer wie der
 * erste — es gab keinen Zwischenspeicher. Jede Ordnerumschaltung, jedes
 * Neuladen baute eine frische IMAP-Sitzung auf.
 *
 * Und es ist nicht nur Tempo: Office 365 drosselt **pro Postfach** ueber alle
 * Verbindungen (Memory #1674). Weniger Abfragen schuetzt auch die anderen
 * Systeme am selben Postfach.
 *
 * ## Warum ausgerechnet die Ordnerliste
 *
 * Weil sie sich fast nie aendert und die einzige Abfrage ist, bei der das
 * gilt. Nachrichtenlisten werden hier NICHT gemerkt: Eine Minute alte Liste
 * heisst „die Mail, die gerade kam, fehlt" — und das ist bei einem Postfach
 * schlimmer als eine halbe Sekunde Wartezeit.
 *
 * ## Vergessen, nicht ablaufen lassen
 *
 * Der Zwischenspeicher wird bei jeder Aenderung ausdruecklich geleert
 * (anlegen, umbenennen, loeschen, Standardordner ergaenzen). Ein neuer Ordner,
 * der zehn Minuten nicht auftaucht, ist genau die Sorte Fehler, die man dem
 * Zwischenspeicher nicht ansieht: Man legt ihn zweimal an.
 *
 * Deshalb liegen die Leerungen im Paket, bei den Wegen, die Ordner aendern —
 * und nicht beim Produkt. Ein Produkt, das einen eigenen Weg baut, muss
 * {@see vergessen()} selbst rufen; das steht in `docs/INTEGRATION.md`.
 *
 * ## Nur Erfolg wird gemerkt
 *
 * Ein Fehlschlag ist kein Bestand. Haette man ihn gemerkt, waere ein kurzer
 * Ausfall zehn Minuten lang ein leeres Postfach — dieselbe Regel wie beim
 * zentralen Speicher („Only success is cached").
 */
class Ordnerliste
{
    /** Der Schlüsselraum. Mit Punkt am Ende, damit `forget` nichts Fremdes trifft. */
    public const PRAEFIX = 'mailbox.folders.';

    /**
     * Die Ordner dieses Postfachs — aus dem Zwischenspeicher, sonst geholt.
     *
     * @param  callable(): list<array<string, mixed>>  $holen
     * @return list<array<string, mixed>>
     */
    public static function fuer(int $konto, callable $holen): array
    {
        $dauer = (int) config('mailbox.folders_cache_ttl', 600);

        if ($dauer <= 0) {
            // Ausgeschaltet. Ein Produkt mit einem Postfach, in dem Ordner
            // laufend entstehen, soll das abschalten koennen — und `0` ist
            // dafuer die eindeutigere Angabe als ein fehlender Schluessel.
            return $holen();
        }

        $gemerkt = Cache::get(self::PRAEFIX.$konto);

        if (is_array($gemerkt)) {
            return $gemerkt;
        }

        $ordner = $holen();

        /*
         * Eine LEERE Liste wird nicht gemerkt.
         *
         * Kein Postfach hat null Ordner — es gibt mindestens INBOX. Eine leere
         * Antwort heisst also „die Abfrage ging schief", und die zehn Minuten
         * lang festzuhalten hiesse, einen Ausfall zu verlaengern. Das ist
         * dieselbe Falle wie „leere Antwort ≠ leerer Bestand", die dieses
         * Projekt schon einmal 36 geloeschte Eintraege gekostet hat.
         */
        if ($ordner !== []) {
            Cache::put(self::PRAEFIX.$konto, $ordner, $dauer);
        }

        return $ordner;
    }

    /**
     * Den Zwischenspeicher dieses Postfachs leeren.
     *
     * Zu rufen, sobald sich Ordner aendern — nicht erst beim naechsten Ablauf.
     */
    public static function vergessen(int $konto): void
    {
        Cache::forget(self::PRAEFIX.$konto);
    }
}
