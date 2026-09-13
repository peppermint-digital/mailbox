<?php

namespace Peppermint\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A mailbox — the core every consuming product shares.
 *
 * ## Where the line runs
 *
 * Core is what a protocol client needs in order to connect and send. The
 * measure is deliberately external: not what we find tidy, but what the
 * protocol demands.
 *
 * Anything describing our own behaviour stays with the product: whether a copy
 * goes to the sent folder, how notifications work, how many hints are pending.
 * A column added to the shared core travels into every product that will never
 * use it.
 *
 * One group breaks that rule and is core anyway: the five health fields. Two
 * products invented them independently, with identical names. Two
 * implementations converging on the same five names is the strongest evidence
 * that it would look the same everywhere.
 *
 * ## Adoption
 *
 * Column names come from configuration, not from the package. One product
 * calls it `email` and `host`, another `email_address` and `imap_host` — the
 * same thing under two names. A package that only runs on fresh tables cannot
 * take over a grown product, and then it gets built a second time.
 */
class MailAccount extends Model
{
    protected $guarded = [];

    protected $hidden = ['password', 'oauth_client_secret', 'oauth_access_token', 'oauth_refresh_token'];

    /**
     * Secrets are encrypted at rest — always, not only when they look secret.
     * A column holding a password sometimes and a hostname at other times ends
     * up in the wrong export eventually.
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'oauth_client_secret' => 'encrypted',
            'oauth_access_token' => 'encrypted',
            'oauth_refresh_token' => 'encrypted',
            'oauth_token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'is_active' => 'boolean',
            'last_reachable' => 'boolean',
            'last_auth_ok' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        return config('mailbox.tables.accounts', 'mail_accounts');
    }

    /**
     * This product's column name for a core field.
     */
    public static function column(string $field): string
    {
        return config("mailbox.columns.{$field}", $field);
    }

    /**
     * Read a core field through whatever the column is actually called, so
     * package code can say `$account->field('imap_host')` without knowing.
     */
    public function field(string $name): mixed
    {
        return $this->getAttribute(static::column($name));
    }

    /**
     * An account from the central store — carried, not stored.
     *
     * When the inventory comes from elsewhere, this is not a table row. The
     * model is filled and kept at `exists = false`, so nobody can accidentally
     * call `save()` and create the very second truth the central store exists
     * to prevent.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromRemote(array $data): self
    {
        $account = new self;
        $account->forceFill($data);
        $account->exists = false;

        return $account;
    }
}
