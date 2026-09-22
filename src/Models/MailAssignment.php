<?php

namespace Peppermint\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wer sich um diese Unterhaltung kuemmert.
 *
 * ## Die Kette, nicht die Nachricht
 *
 * Zugewiesen wird die KONVERSATION. Weist man eine einzelne Nachricht zu, ist
 * die naechste Antwort in derselben Sache wieder niemandem zugeordnet — und
 * dieselbe Unterhaltung wird zweimal sortiert.
 *
 * `message_id` bleibt trotzdem daneben stehen: Sie sagt, AN WELCHER Nachricht
 * die Zuweisung entstanden ist. Und sie faengt den Altbestand auf, der noch
 * keine Kettenkennung hat.
 *
 * ## Warum kein Fremdschluessel auf die Nutzer
 *
 * Das Paket weiss nicht, wie die Nutzertabelle des Produkts heisst oder
 * welchen Typ ihr Schluessel hat. `assigned_to_user_id` ist deshalb eine
 * blanke Zahl. Wer prueft, ob es die Person gibt und ob sie ins Postfach darf,
 * ist das Produkt — es kennt seine Leute.
 *
 * ## Der Tabellenname ist einstellbar
 *
 * Der Projekt-Manager fuehrt diese Daten seit Juni 2026 unter
 * `email_assignments`. Sie umzubenennen hiesse, gewachsene Daten zu bewegen,
 * ohne dass jemand etwas davon haette — also stellt er den Namen ein
 * (`mailbox.tables.assignments`) und behaelt seine Tabelle.
 */
class MailAssignment extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    protected $fillable = [
        'email_account_id',
        'message_id',
        'thread_id',
        'assigned_to_user_id',
        'assigned_by_user_id',
        'status',
    ];

    public function getTable(): string
    {
        return config('mailbox.tables.assignments', 'mail_assignments');
    }
}
