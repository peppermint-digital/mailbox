<?php

use Illuminate\Database\Migrations\Migration;
use Peppermint\Mailbox\Database\AssignmentsTable;

/**
 * Laeuft nur, wo `mailbox.run_migrations` an ist. Produkte ohne eigene
 * Postfach-Tabellen (CRM, Verwaltung) rufen {@see AssignmentsTable::create()}
 * stattdessen aus einer eigenen Migration — das Schema steht trotzdem nur an
 * einer Stelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        AssignmentsTable::create();
    }

    public function down(): void
    {
        AssignmentsTable::drop();
    }
};
