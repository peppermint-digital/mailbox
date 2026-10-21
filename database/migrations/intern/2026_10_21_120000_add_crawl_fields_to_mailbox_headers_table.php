<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was ein DURCHLAUF weiß, und die Liste nicht braucht (#8440).
 *
 * ## Warum dieselbe Tabelle zwei Verträge bedient
 *
 * Im Manager standen zwei Kopfzeilen-Verzeichnisse in derselben Datenbank:
 * `email_headers` seit dem 07.08.2026 (87.742 Zeilen, alle 15 Minuten gefüllt, für
 * Suche und Konversationen) und `mailbox_headers` seit dem 20.10.2026 (bei
 * jedem Seitenaufruf gefüllt, für die Liste). Dieselbe Tatsache an zwei Orten.
 *
 * Der Nutzer dazu: „Macht ja keinen Sinn, da Doppelungen zu haben. Wir wollten
 * ja ein cleanes, sauberes Paket bauen."
 *
 * Eine Tabelle, zwei LESEVERTRÄGE — und die müssen ausgeschrieben sein, sonst
 * liest der eine, was für den anderen gilt:
 *
 * | | Liste | Suche |
 * |---|---|---|
 * | Belegung | immer vom Server | aus dem Verzeichnis |
 * | Betreff, Absender, Datum | aus dem Verzeichnis | aus dem Verzeichnis |
 * | `is_seen`, `is_flagged` | **IMMER vom Server** | aus dem Verzeichnis, als Stand des letzten Durchlaufs |
 * | Vollständigkeit | nicht nötig (nur Gezeigtes) | nötig (ein Durchlauf füllt) |
 *
 * ## Die Entscheidung, die der Nutzer getroffen hat
 *
 * Lesemarken ändert jeder andere Client jederzeit. Für die LISTE sind sie
 * deshalb tabu — eine gespeicherte Lesemarke ist der Fehler, den niemand als
 * Fehler erkennt. Für die SUCHE gab es zwei Wege, und beide waren vertretbar:
 *
 * 1. Die Suche holt die Flags für ihre Treffer live (~54 ms je Postfach).
 * 2. Das Verzeichnis führt sie als „Stand des letzten Durchlaufs".
 *
 * Der Nutzer hat am 21.10.2026 den zweiten gewählt: „Das, was am meisten Sinn
 * macht, wäre der Ballpunkt 2." Die Suche bleibt damit schnell, und die Angabe
 * lügt nicht — `indexed_at` sagt, von wann sie ist.
 *
 * ## Warum die drei neuen Spalten NULL zulassen
 *
 * `NULL` heißt „kein Durchlauf hat diese Zeile gesehen" — und eine Zeile, die
 * nur die Liste geschrieben hat, ist genau das. `0` hieße „gelesen: nein", und
 * das wäre eine Behauptung über etwas, das niemand gefragt hat. Dieselbe
 * Unterscheidung wie bei `attachment_count`, und sie hat Bug #1025 gekostet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mailbox_headers') || Schema::hasColumn('mailbox_headers', 'thread_id')) {
            return;
        }

        Schema::table('mailbox_headers', function (Blueprint $table): void {
            // Die Kette, an der die Konversationen hängen. Im Manager heisst
            // sie genauso und wird von `EmailThreadKey` gebildet.
            $table->string('thread_id', 255)->nullable()->after('message_id');

            /*
             * Lesemarken — NUR fuer die Suche, und nur als Stand des letzten
             * Durchlaufs. Die Liste liest sie nicht; dafuer gibt es einen Test,
             * weil genau das der Fehler waere, den niemand sieht.
             */
            $table->boolean('is_seen')->nullable()->after('attachment_count');
            $table->boolean('is_flagged')->nullable()->after('is_seen');

            // Von wann der Stand ist. Ohne das waere „Stand des letzten
            // Durchlaufs" eine Behauptung ohne Datum.
            $table->timestamp('indexed_at')->nullable()->after('is_flagged');

            // Die Frage der Konversationen: „was gehoert zu dieser Kette".
            $table->index(['account_id', 'thread_id'], 'mailbox_headers_konto_kette');

        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mailbox_headers') || ! Schema::hasColumn('mailbox_headers', 'thread_id')) {
            return;
        }

        Schema::table('mailbox_headers', function (Blueprint $table): void {
            $table->dropIndex('mailbox_headers_konto_kette');
            $table->dropColumn(['thread_id', 'is_seen', 'is_flagged', 'indexed_at']);
        });
    }
};
