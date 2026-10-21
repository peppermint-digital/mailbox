<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kopfzeilen-Verzeichnis der Listenansicht (#6119).
 *
 * ## Warum überhaupt — gemessen, nicht vermutet
 *
 * Am 20.10.2026 an `department@peppermint-digital.de` (54 Nachrichten im
 * Posteingang), alles in EINER offenen Verbindung:
 *
 *     nur UIDs (SEARCH)                      69 ms
 *     + FLAGS                               123 ms
 *     + GANZER Kopfblock (490 KB)       660–1031 ms
 *     + nur 5 Kopffelder (12 KB)            688 ms
 *     + BODYSTRUCTURE                 2234–13024 ms
 *
 * Die dritte und die vierte Zeile sind der ganze Grund für diese Tabelle: Zwölf
 * Kilobyte kosten so viel wie vierhundertneunzig. Der Engpass ist nicht die
 * Leitung, sondern die Arbeit, die Exchange **je Nachricht** leistet — rund
 * 12,7 ms, unabhängig davon, wie viel davon zurückkommt.
 *
 * Damit ist jeder billigere Abruf gemessen tot: eine Feldauswahl
 * (`BODY.PEEK[HEADER.FIELDS (…)]`) lässt sich bauen und bringt nichts. Es gibt
 * genau einen Weg, der etwas spart: den Server **nicht** je Nachricht fragen.
 *
 * ## Die Invariante, die dieses Verzeichnis für eine Liste taugbar macht
 *
 * > Es beantwortet „was steht in UID 46807", **nie** „welche Mails liegen in
 * > diesem Ordner".
 *
 * Die Belegung eines Ordners kommt bei JEDEM Aufruf vom Server (UID-SEARCH,
 * 69 ms). Verzeichnet ist nur der Inhalt je Kennung. Deshalb kann hier keine
 * Mail fehlen, die angekommen ist, und keine erscheinen, die gelöscht wurde —
 * die beiden Fehler, die ein Listen-Zwischenspeicher sonst STILL macht.
 *
 * Das ist auch der Unterschied zum Verzeichnis des Managers (`email_headers`,
 * seit 07.08.2026, 87.742 Zeilen am 21.10.): Das wird alle 15 Minuten gefüllt und bedient
 * Suche und Konversationen. Für eine Suche ist eine Stunde Rückstand belanglos,
 * für eine Mailliste ist sie ein Fehler. Dieselbe Tabelle, anderer Vertrag.
 *
 * ## Zweite Invariante: nur Unveränderliches
 *
 * Verzeichnet wird ausschließlich, was sich für eine gegebene UID nicht mehr
 * ändern KANN — Betreff, Absender, Datum, Message-ID, Bezüge, Anhangzahl.
 *
 * `is_seen` und `is_flagged` stehen bewusst NICHT hier. Flags ändert jeder
 * andere Client jederzeit; sie kommen bei jedem Aufruf vom Server (FLAGS für
 * die sichtbaren Zeilen, ~54 ms). Eine gespeicherte Lesemarke wäre genau die
 * Art Fehler, die niemand als Fehler erkennt: Die Liste sieht richtig aus und
 * ist falsch.
 *
 * ## Warum `attachment_count` NULL sein darf
 *
 * `0` heißt „keine Anhänge", `NULL` heißt „noch nicht gefragt" — die
 * Unterscheidung, die Bug #1025 gekostet hat. Die Struktur ist der teuerste
 * Abruf überhaupt, deshalb wird sie nur für Zeilen geholt, die wirklich
 * angezeigt werden. Ein Fenster von hundert Zeilen hat also Kopfzeilen, aber
 * nur fünfundzwanzig davon eine Anhangzahl.
 *
 * ## Warum `uid` eine Zeichenkette ist, obwohl IMAP zählt
 *
 * Weil der Manager dieselbe Spalte am 21.09.2026 verbreitern musste: JMAP
 * vergibt Kennungen wie `cqiaaaaus`, und der Verzeichnis-Lauf scheiterte für
 * dieses Postfach bei jedem Durchgang mit „Incorrect integer value". Dieses Verzeichnis
 * bedient heute nur IMAP — die Spalte ist trotzdem von Anfang an breit, weil
 * die Lehre schon bezahlt ist.
 *
 * Breitenrechnung für den Eindeutig-Index: 8 (Konto) + 1020 (`folder`, 255 in
 * utf8mb4) + 764 (`uid`, 191) ≈ 1792 Bytes, weit unter der Grenze von 3072.
 *
 * ## `uidvalidity` je Zeile und nicht je Ordner
 *
 * Wechselt sie, sind ALLE Kennungen dieses Ordners wertlos — aus einem Ordner
 * ist über Nacht ein anderer geworden. Je Zeile mitgeführt ist das vier Bytes
 * und braucht keine zweite Tabelle: Wer mit der heutigen Gültigkeit fragt,
 * bekommt die alten Zeilen gar nicht zu sehen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mailbox_headers')) {
            return;
        }

        Schema::create('mailbox_headers', function (Blueprint $table): void {
            $table->id();

            /*
             * Kein Fremdschlüssel: In Brain kommen die Konten aus der zentralen
             * Ablage und haben hier gar keine Zeile (MailAccount::fromRemote).
             * Ein Fremdschlüssel wäre dort nicht erfüllbar.
             */
            $table->unsignedBigInteger('account_id');
            $table->string('folder', 255);
            $table->string('uid', 191);
            $table->unsignedBigInteger('uidvalidity')->nullable();

            // Genau die Drahtform der Listenzeile, Feld für Feld.
            $table->string('message_id', 255)->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('from_address', 500)->nullable();
            $table->string('from_name', 500)->nullable();

            /*
             * Die Zeichenkette, nicht ein Zeitstempel. Die Liste gibt
             * `toIso8601String()` heraus, also MIT Zeitzonen-Versatz; ein
             * Zeitstempel käme als UTC zurück und die Ausgabe wäre eine andere
             * als auf dem lebenden Weg. Sortiert wird in PHP über `strtotime`,
             * das den Versatz versteht.
             */
            $table->string('date', 40)->nullable();

            // NULL heißt „Struktur noch nicht geholt" — siehe Klassentext.
            $table->unsignedSmallInteger('attachment_count')->nullable();

            $table->string('in_reply_to', 255)->nullable();
            $table->text('mail_references')->nullable();

            $table->timestamps();

            $table->unique(['account_id', 'folder', 'uid'], 'mailbox_headers_konto_ordner_uid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_headers');
    }
};
