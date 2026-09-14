<?php

namespace Peppermint\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 * ## Shared mailboxes
 *
 * A mailbox without an owner is shared — a team address several people work
 * in, not a private one. That is not a detail on top: asking for "the
 * mailboxes of this person" and getting only the private ones is the wrong
 * answer in every product that has a team address, which is all of them.
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

    /**
     * Did this account come from elsewhere?
     *
     * The difference decides how {@see field()} reads. The column map
     * describes THIS product's table — central data always carries the
     * package's own field names. Reading both the same way yields a silent
     * `null`, and with it "differs" for every single field.
     */
    protected bool $remote = false;

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
        return $this->getAttribute($this->remote ? $name : static::column($name));
    }

    /** Did this account come from the central store? */
    public function isRemote(): bool
    {
        return $this->remote;
    }

    /**
     * A mailbox with no owner is a shared one.
     */
    public function isShared(): bool
    {
        return $this->getAttribute(static::column('user_id')) === null;
    }

    /**
     * Everything this person may work in: their own mailboxes plus the shared
     * ones they are allowed to use.
     *
     * Three levels, and the third is the one that is easy to miss:
     *
     * 1. own — owner is this person
     * 2. shared without an access list — everybody (a team address nobody
     *    restricted stays open; anything else would silently take mailboxes
     *    away on the day the feature is switched on)
     * 3. shared with an access list — only the people on it
     *
     * Products without access lists leave `sharing.table` at null and get
     * levels 1 and 2. Nothing here assumes a User class: the package has no
     * business knowing what a product calls its people.
     */
    public function scopeAccessibleBy(Builder $query, int|string $userId): Builder
    {
        $owner = static::column('user_id');

        return $query->where(function (Builder $scope) use ($owner, $userId): void {
            $scope->where($owner, $userId)
                ->orWhere(function (Builder $shared) use ($owner, $userId): void {
                    $shared->whereNull($owner);

                    $liste = static::sharingTable();

                    if ($liste === null) {
                        return;
                    }

                    [$kontoSpalte, $nutzerSpalte] = static::sharingKeys();

                    $shared->where(function (Builder $sichtbar) use ($liste, $kontoSpalte, $nutzerSpalte, $userId): void {
                        $sichtbar
                            // No entry at all: open to everyone.
                            ->whereNotExists(fn ($frage) => $frage->select(DB::raw(1))->from($liste)
                                ->whereColumn($kontoSpalte, $this->getTable().'.id'))
                            ->orWhereExists(fn ($frage) => $frage->select(DB::raw(1))->from($liste)
                                ->whereColumn($kontoSpalte, $this->getTable().'.id')
                                ->where($nutzerSpalte, $userId));
                    });
                });
        });
    }

    /**
     * The access-list table, or null when this product has none — including
     * the case where it is configured but not migrated yet, because a query
     * against a missing table fails harder than a missing feature.
     */
    public static function sharingTable(): ?string
    {
        $table = config('mailbox.sharing.table');

        if ($table === null || ! Schema::hasTable($table)) {
            return null;
        }

        return $table;
    }

    /** @return array{0: string, 1: string} */
    public static function sharingKeys(): array
    {
        return [
            config('mailbox.sharing.account_key', 'mail_account_id'),
            config('mailbox.sharing.user_key', 'user_id'),
        ];
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
        $account->remote = true;

        return $account;
    }
}
