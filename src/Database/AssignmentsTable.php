<?php

namespace Peppermint\Mailbox\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Tabelle fuer die Zustaendigkeiten — einmal beschrieben, von jedem
 * Produkt selbst angelegt.
 *
 * ## Warum nicht einfach die Paket-Migration
 *
 * Die laeuft nur, wo `mailbox.run_migrations` an ist. CRM und Verwaltung
 * haben sie aus: Sie fuehren gar keine eigene Postfach-Tabelle, ihre Konten
 * kommen aus AI Brain. Die Paket-Migration anzuschalten haette ihnen
 * `mail_accounts` mit aufgedraengt — eine Tabelle, die dort leer bliebe.
 *
 * Also andersherum: Das Schema steht hier, einmal. Wer die Zustaendigkeiten
 * braucht, legt eine kurze Migration an, die diese Methode ruft. So gibt es
 * weiter genau EINE Beschreibung der Tabelle, und trotzdem entscheidet jedes
 * Produkt selbst, was bei ihm entsteht.
 */
class AssignmentsTable
{
    public static function create(): void
    {
        $name = config('mailbox.tables.assignments', 'mail_assignments');

        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $tabelle) {
            $tabelle->id();
            $tabelle->unsignedBigInteger('email_account_id')->index();

            // An welcher Nachricht die Zuweisung entstanden ist.
            $tabelle->string('message_id', 255)->index();

            // Worum es wirklich geht: die Kette. Null bei Nachrichten, deren
            // Kopfzeilen keine hergeben.
            $tabelle->string('thread_id', 255)->nullable()->index();

            // Kein Fremdschluessel: Das Paket weiss nicht, wie die
            // Nutzertabelle des Produkts heisst oder welchen Typ ihr
            // Schluessel hat.
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

    public static function drop(): void
    {
        Schema::dropIfExists(config('mailbox.tables.assignments', 'mail_assignments'));
    }
}
