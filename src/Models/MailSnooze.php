<?php

namespace Peppermint\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * „Nicht jetzt — erinnere mich wieder ab dann."
 *
 * Pro Person: Wer in einem Gruppenpostfach etwas fuer ALLE verschwinden
 * liesse, nimmt der Kollegin eine Nachricht weg, von der sie nichts weiss.
 *
 * Es gibt keinen Hintergrundlauf, der etwas zurueckholt. Eine geschlummerte
 * Kette wird beim Anzeigen uebersprungen, solange `snooze_until` in der
 * Zukunft liegt — danach steht sie wieder da, ohne dass jemand etwas tun
 * muss. Ein Lauf, der Zeilen umschreibt, koennte ausfallen; ein Vergleich
 * mit der Uhr nicht.
 */
class MailSnooze extends Model
{
    protected $fillable = ['email_account_id', 'user_id', 'thread_id', 'message_id', 'snooze_until'];

    protected $casts = ['snooze_until' => 'datetime'];

    public function getTable(): string
    {
        return config('mailbox.tables.snoozes', 'mail_snoozes');
    }
}
