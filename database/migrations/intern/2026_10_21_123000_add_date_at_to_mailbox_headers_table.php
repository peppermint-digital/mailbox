<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Mailbox\Index\Kopfzeilenverzeichnis;

/**
 * Ein Datum zum Sortieren in SQL (#8440).
 *
 * ## Warum das Datum zweimal in der Zeile steht
 *
 * `date` ist die Drahtform: die Zeichenkette, die `toIso8601String()` erzeugt,
 * MIT dem Zeitzonen-Versatz des Absenders. Sie steht so in der Tabelle, damit
 * eine Listenzeile aus dem Verzeichnis Byte für Byte dasselbe herausgibt wie
 * eine vom Server geholte — am 20.10.2026 über 25 Zeilen und 13 Felder
 * nachgewiesen, null Abweichung.
 *
 * Die Suche aber SORTIERT und AGGREGIERT in SQL (`orderByDesc('date')`,
 * `min('date')`). Auf einer Zeichenkette ist das lexikalisch, und damit falsch,
 * sobald Versätze gemischt sind:
 *
 *     2026-01-01T23:00:00+02:00   derselbe Augenblick
 *     2026-01-01T21:00:00+00:00   andere Zeichenkette, andere Reihenfolge
 *
 * Bei Mail-Kopfzeilen sind die Versätze immer gemischt — jeder Absender
 * schickt seinen eigenen.
 *
 * ## Es sind nicht zwei Wahrheiten
 *
 * `date_at` wird im Verzeichnis AUS `date` abgeleitet, in derselben Anweisung
 * ({@see Kopfzeilenverzeichnis::schreiben()}). Ein
 * Aufrufer kann die beiden nicht auseinanderlaufen lassen, weil er `date_at`
 * nicht setzt. Eine abgeleitete Sortierspalte, keine zweite Angabe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mailbox_headers') || Schema::hasColumn('mailbox_headers', 'date_at')) {
            return;
        }

        Schema::table('mailbox_headers', function (Blueprint $table): void {
            $table->timestamp('date_at')->nullable()->after('date');
            $table->index(['account_id', 'date_at'], 'mailbox_headers_konto_date_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mailbox_headers') || ! Schema::hasColumn('mailbox_headers', 'date_at')) {
            return;
        }

        Schema::table('mailbox_headers', function (Blueprint $table): void {
            $table->dropIndex('mailbox_headers_konto_date_at');
            $table->dropColumn('date_at');
        });
    }
};
