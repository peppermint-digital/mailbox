<?php

namespace Peppermint\Mailbox\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Tabelle fuers Schlummern — einmal beschrieben, von jedem Produkt selbst
 * angelegt (siehe {@see AssignmentsTable} fuer den Grund).
 *
 * ## Pro PERSON, nicht pro Postfach
 *
 * Das ist der Unterschied zur Zuweisung. „Ich kuemmere mich spaeter darum"
 * ist eine Aussage ueber den eigenen Arbeitstag. Wer in einem
 * Gruppenpostfach etwas fuer ALLE verschwinden liesse, nimmt der Kollegin
 * eine Nachricht weg, von der sie nichts weiss.
 */
class SnoozesTable
{
    public static function create(): void
    {
        $name = config('mailbox.tables.snoozes', 'mail_snoozes');

        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $tabelle) {
            $tabelle->id();
            $tabelle->unsignedBigInteger('email_account_id')->index();

            // Kein Fremdschluessel auf die Nutzer: Das Paket kennt ihre
            // Tabelle nicht.
            $tabelle->unsignedBigInteger('user_id')->index();

            // Geschlummert wird die KETTE — sonst taucht die naechste Antwort
            // in derselben Sache sofort wieder auf.
            $tabelle->string('thread_id', 255);
            $tabelle->string('message_id', 255)->nullable();
            $tabelle->timestamp('snooze_until');
            $tabelle->timestamps();

            $tabelle->unique(['user_id', 'email_account_id', 'thread_id'], 'mail_snoozes_unique');
            $tabelle->index(['user_id', 'email_account_id', 'snooze_until'], 'mail_snoozes_until');
        });
    }

    public static function drop(): void
    {
        Schema::dropIfExists(config('mailbox.tables.snoozes', 'mail_snoozes'));
    }
}
