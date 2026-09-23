<?php

namespace Peppermint\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Stelle, an der eine Nachricht gesehen wurde.
 *
 * Eine Nachricht, die dreimal umgezogen ist, hat drei Zeilen — zwei
 * geschlossene und eine offene. „Wo liegt sie jetzt?" ist damit beantwortbar,
 * und „am 5. hat sie jemand ins Archiv geschoben" auch.
 *
 * ## `gone_at` ist kein Loeschvermerk
 *
 * Es heisst: An DIESER Stelle liegt sie nicht mehr. Ob sie woanders liegt,
 * steht in der naechsten Zeile; ob sie ueberhaupt noch im Postfach ist, steht
 * an der Nachricht.
 */
class MailLocation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'seen_at' => 'datetime',
        'gone_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('mailbox.tables.locations', 'mail_locations');
    }

    /**
     * @return BelongsTo<MailMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    public function istOffen(): bool
    {
        return $this->gone_at === null;
    }
}
