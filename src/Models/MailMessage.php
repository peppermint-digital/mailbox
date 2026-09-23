<?php

namespace Peppermint\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Eine Nachricht in der Ablage — das, worauf Verweise zeigen duerfen.
 *
 * ## Der Hash wird nicht von Hand gesetzt
 *
 * `message_id_hash` traegt den Riegel gegen Doppelanlagen. Wer ihn vergisst,
 * bekommt keine Fehlermeldung, sondern eine Ablage, in der dieselbe Nachricht
 * mehrfach steht — der Riegel greift dann auf einem leeren Wert und macht aus
 * allen Nachrichten eines Postfachs eine einzige.
 *
 * Deshalb setzt das Modell ihn selbst, bei jedem Schreiben. Eine Pflicht, an
 * die man sich erinnern muss, ist keine.
 *
 * ## Warum kein `deleted_at`
 *
 * Weil hier nichts geloescht wird, wenn jemand im Postfach loescht. Dafuer gibt
 * es `missing_since` — eine Feststellung ueber das Postfach, keine ueber die
 * Ablage. Ein `SoftDeletes` daneben waere die Einladung, das eine fuer das
 * andere zu halten.
 */
class MailMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'recipients' => 'array',
        'sent_at' => 'datetime',
        'captured_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'missing_since' => 'datetime',
        'has_attachments' => 'boolean',
        'is_root' => 'boolean',
    ];

    public function getTable(): string
    {
        return (string) config('mailbox.tables.messages', 'mail_messages');
    }

    protected static function booted(): void
    {
        static::saving(function (self $nachricht): void {
            $nachricht->message_id_hash = self::hash((string) $nachricht->message_id);
        });
    }

    /**
     * Der Schluessel, unter dem eine Message-ID indiziert wird.
     *
     * Ausdruecklich oeffentlich: Wer in der Ablage nach einer Nachricht sucht,
     * muss denselben Weg gehen wie das Schreiben — sonst sucht er auf einer
     * Spalte ohne Index und findet bei 200.000 Zeilen nichts in
     * vertretbarer Zeit.
     */
    public static function hash(string $messageId): string
    {
        return hash('sha256', $messageId);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $frage
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeFuerMessageId($frage, int $accountId, string $messageId)
    {
        return $frage->where('email_account_id', $accountId)
            ->where('message_id_hash', self::hash($messageId));
    }

    /**
     * Liegt sie noch im Postfach?
     *
     * `false` heisst NICHT, dass sie weg ist — sie ist hier. Es heisst, dass
     * sie im Postfach nicht mehr gefunden wurde.
     */
    public function imPostfach(): bool
    {
        return $this->missing_since === null;
    }

    /**
     * @return HasMany<MailLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(MailLocation::class, 'mail_message_id');
    }

    /**
     * Wo sie jetzt liegt — der Ort ohne `gone_at`.
     *
     * Mehrere offene Orte sind moeglich und kein Fehler: Eine Nachricht kann
     * in zwei Ordnern liegen, wenn jemand kopiert statt verschoben hat.
     *
     * @return HasMany<MailLocation, $this>
     */
    public function aktuelleOrte(): HasMany
    {
        return $this->locations()->whereNull('gone_at');
    }

    /**
     * Ist das der Anfang der Kette?
     *
     * Gelesen und nicht gerechnet: Der Vergleich passiert beim Erfassen, wo
     * die normalisierte Form vorliegt.
     */
    public function istWurzel(): bool
    {
        return (bool) $this->is_root;
    }

    /**
     * @return HasOne<MailBody, $this>
     */
    public function body(): HasOne
    {
        return $this->hasOne(MailBody::class, 'mail_message_id');
    }
}
