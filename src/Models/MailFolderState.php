<?php

namespace Peppermint\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Der Zustand eines Ordners beim letzten Blick.
 *
 * Eine Zeile je Postfach und Ordner. Sie beantwortet die eine Frage, die einen
 * stuendlichen Lauf ueber 220 Ordner ueberhaupt erst vertretbar macht: Hat
 * sich hier seit dem letzten Mal etwas getan?
 */
class MailFolderState extends Model
{
    protected $guarded = [];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('mailbox.tables.folder_states', 'mail_folder_states');
    }

    /**
     * Stimmt der Zustand mit dem ueberein, der jetzt gemeldet wird?
     *
     * `null`-Werte zaehlen NICHT als Uebereinstimmung. Ein Postfach, das keine
     * Zahlen liefert — JMAP etwa —, wird damit nie uebersprungen. Das ist
     * richtig so: „ich weiss es nicht" ist kein „hat sich nichts geaendert".
     *
     * @param  array{uidvalidity: int|null, uidnext: int|null, messages: int|null}  $jetzt
     */
    public function unveraendert(array $jetzt): bool
    {
        foreach (['uidvalidity', 'uidnext', 'messages'] as $feld) {
            if ($jetzt[$feld] === null || $this->{$feld} === null) {
                return false;
            }

            if ((int) $this->{$feld} !== (int) $jetzt[$feld]) {
                return false;
            }
        }

        return true;
    }
}
