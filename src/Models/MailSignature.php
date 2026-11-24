<?php

namespace Peppermint\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eine Signatur einer Person — fuer alle ihre Postfaecher oder fuer eines.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $mail_account_id
 * @property string $name
 * @property string $body_html
 * @property bool $is_default
 */
class MailSignature extends Model
{
    protected $fillable = ['user_id', 'mail_account_id', 'name', 'body_html', 'is_default'];

    protected $casts = ['is_default' => 'boolean'];

    public function getTable(): string
    {
        return config('mailbox.tables.signatures', 'mail_signatures');
    }

    /**
     * Was an einem Postfach zur Wahl steht: die des Postfachs und die fuer
     * alle, Postfach zuerst, darin die Vorgabe.
     *
     * Dieselbe Reihenfolge wie in AI Brain (`fuerBereich`) — die Verfassen-
     * Seite nimmt die erste Vorgabe, also entscheidet die Reihenfolge, welche
     * Unterschrift unter der Mail steht. Ohne Postfach nur die fuer alle.
     *
     * @param  Builder<MailSignature>  $frage
     * @return Builder<MailSignature>
     */
    public function scopeFuerBereich(Builder $frage, int $person, int|string|null $konto): Builder
    {
        return $frage
            ->where('user_id', $person)
            ->when(
                $konto !== null && $konto !== '',
                fn (Builder $q) => $q->where(
                    fn (Builder $k) => $k->whereNull('mail_account_id')->orWhere('mail_account_id', $konto),
                ),
                fn (Builder $q) => $q->whereNull('mail_account_id'),
            )
            ->orderByRaw('mail_account_id IS NULL')
            ->orderByDesc('is_default')
            ->orderBy('name');
    }
}
