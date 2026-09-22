<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wer sich um welche Unterhaltung kuemmert.
 *
 * Der Tabellenname ist einstellbar: Der Projekt-Manager fuehrt diese Daten
 * seit Juni 2026 unter `email_assignments` und behaelt sie — die Migration
 * laeuft dort ins Leere, weil die Tabelle schon steht.
 *
 * Kein Fremdschluessel auf die Nutzer: Das Paket weiss nicht, wie deren
 * Tabelle heisst oder welchen Typ ihr Schluessel hat. Wer prueft, ob es die
 * Person gibt und ob sie ins Postfach darf, ist das Produkt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelle = config('mailbox.tables.assignments', 'mail_assignments');

        if (Schema::hasTable($tabelle)) {
            return;
        }

        Schema::create($tabelle, function (Blueprint $tabelle) {
            $tabelle->id();
            $tabelle->unsignedBigInteger('email_account_id')->index();

            // An welcher Nachricht die Zuweisung entstanden ist.
            $tabelle->string('message_id', 255)->index();

            // Worum es wirklich geht: die Kette. Null bei Nachrichten, deren
            // Kopfzeilen keine hergeben.
            $tabelle->string('thread_id', 255)->nullable()->index();

            $tabelle->unsignedBigInteger('assigned_to_user_id')->index();
            $tabelle->unsignedBigInteger('assigned_by_user_id')->nullable();
            $tabelle->string('status', 16)->default('open')->index();
            $tabelle->timestamps();

            // Eine Kette gehoert je Postfach genau einer Person. Ohne den
            // Riegel entstehen bei zwei gleichzeitigen Klicks zwei Zeilen, und
            // danach zeigt die Liste eine davon — welche, entscheidet der
            // Zufall.
            $tabelle->unique(['email_account_id', 'thread_id'], 'mail_assignments_thread_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mailbox.tables.assignments', 'mail_assignments'));
    }
};
