<?php

namespace Peppermint\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Peppermint\Mailbox\Content\Gespraechstext;

/**
 * Der aufbereitete Text einer Nachricht — das, was der Verlauf zeigt.
 *
 * ## Die Fassung steht dabei
 *
 * Die Erkennung in {@see Gespraechstext} ist Heuristik und wird besser. Ohne
 * `parser_version` haette man spaeter nur die Wahl zwischen „alles neu
 * rechnen" (teuer, und die Ablage kann gross sein) und „alles alt lassen"
 * (dann bleibt die Verbesserung wirkungslos).
 *
 * Mit ihr ist Nacharbeiten eine Abfrage: alles, was eine aeltere Fassung
 * gesehen hat.
 */
class MailBody extends Model
{
    /**
     * Die Fassung der Aufbereitung.
     *
     * Hochzaehlen, wenn sich das ERGEBNIS aendert — nicht bei jeder Aenderung
     * an der Klasse. Eine Versionsnummer, die sich bei Umbenennungen
     * mitbewegt, erzwingt Nacharbeit ohne Nutzen und wird dann abgeschaltet.
     */
    public const FASSUNG = '1';

    protected $guarded = [];

    protected $casts = [
        'parsed_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('mailbox.tables.bodies', 'mail_bodies');
    }

    /**
     * Aus einer aufbereiteten Nachricht — die Felder in einem Rutsch.
     *
     * @return array<string, mixed>
     */
    public static function felder(Gespraechstext $text): array
    {
        return [
            'content' => $text->inhalt(),
            'quote' => $text->zitat(),
            'signature' => $text->signatur(),
            'footer' => $text->fusszeile(),
            'parser_version' => self::FASSUNG,
            'parsed_at' => now(),
        ];
    }

    /**
     * Wurde das mit einer aelteren Fassung erzeugt?
     */
    public function veraltet(): bool
    {
        return (string) $this->parser_version !== self::FASSUNG;
    }

    /**
     * @return BelongsTo<MailMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }
}
