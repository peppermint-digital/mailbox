<?php

namespace Peppermint\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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

    /**
     * Did a central row overwrite this one's connection settings?
     *
     * Separate from {@see $remote}: that one says "this is not a table row at
     * all", this one says "it is, but the connection came from elsewhere".
     */
    protected bool $centrallyMerged = false;

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

    /**
     * Which protocol this mailbox is reached with.
     *
     * Falls back to `imap` rather than asking the schema: a product that
     * adopted its grown table and never added the column reads `null` here,
     * and `null` means the same thing it meant before the column existed.
     * Asking `Schema::hasColumn` would put a query in front of every single
     * connection to answer a question the default already answers.
     *
     * ## Why this is not called protocol()
     *
     * Because the column is. Eloquent takes a method whose name matches an
     * attribute for a relation and calls it from `getAttribute()` — so
     * `protocol()` reading `field('protocol')` calls itself until the memory
     * is gone. The failure is a fatal out-of-memory in an unrelated place,
     * not a hint about naming, so the next person to try it loses the same
     * half hour.
     */
    public function transport(): string
    {
        $wert = $this->field('protocol');

        return is_string($wert) && $wert !== '' ? $wert : 'imap';
    }

    public function usesJmap(): bool
    {
        return $this->transport() === 'jmap';
    }

    /**
     * Where the JMAP session document lives.
     *
     * Stored only where it has to be. The standard says the session document
     * is found at `/.well-known/jmap` on the mail host, and for a server that
     * follows it — ours does — a stored URL would be a second copy of the
     * hostname that can drift away from the first.
     *
     * Null when there is no host at all: a mailbox without one cannot be
     * reached by any protocol, and inventing a URL from nothing would turn
     * that into a confusing connection error instead of a clear no.
     */
    public function jmapSessionUrl(): ?string
    {
        $eigene = $this->field('jmap_url');

        if (is_string($eigene) && $eigene !== '') {
            return $eigene;
        }

        $host = $this->field('imap_host');

        if (! is_string($host) || $host === '') {
            return null;
        }

        return 'https://'.$host.'/.well-known/jmap';
    }

    /**
     * Does this mailbox sign in with a token instead of a password?
     *
     * Read from `auth_type`, because that is the setting a person made — not
     * guessed from whether a token happens to be lying around. A mailbox whose
     * OAuth consent was withdrawn still has its old tokens in the row; guessing
     * from their presence would keep trying an authentication that cannot work
     * any more, instead of failing where someone can see it.
     */
    public function usesOAuth(): bool
    {
        return $this->field('auth_type') === 'oauth';
    }

    /**
     * Is the access token so close to expiry that the next call would fail?
     *
     * The few minutes of slack are the point: a token valid for ten more
     * seconds is worthless for a call that takes twelve.
     */
    public function isTokenExpiringSoon(int $minutes = 5): bool
    {
        $expires = $this->field('oauth_token_expires_at');

        if (! $this->usesOAuth() || ! $expires) {
            return false;
        }

        return Carbon::parse($expires)->subMinutes($minutes)->isPast();
    }

    /** Did this account come from the central store? */
    public function isRemote(): bool
    {
        return $this->remote;
    }

    /**
     * Are this mailbox's connection settings maintained centrally?
     *
     * Three states, not two: a row can be local-only, carried from the centre,
     * or a local row whose connection was overwritten from the centre. The
     * third is the one a grown product ends up in, and a product that cannot
     * tell it from the first will eventually offer a password field that
     * writes into the void.
     */
    public function isCentrallyManaged(): bool
    {
        return $this->remote || $this->centrallyMerged;
    }

    /**
     * This row with the central connection settings laid over it.
     *
     * Not saved, and deliberately so: the values belong to the centre, and
     * writing them back would create the second copy this whole arrangement
     * exists to avoid. `exists` stays true — it IS a table row, and everything
     * the product hung on its id keeps working.
     *
     * @param  array<string, mixed>  $werte  already in this product's column names
     */
    public function withCentral(array $werte): self
    {
        $konto = clone $this;
        $konto->centrallyMerged = true;

        foreach ($werte as $spalte => $wert) {
            $konto->setAttribute($spalte, $wert);
        }

        // Was gerade hereingelegt wurde, ist nicht "geaendert" im Sinne von
        // speicherbar. Ohne das wuerde ein save() des Produkts die zentralen
        // Werte in die eigene Tabelle schreiben — genau die zweite Wahrheit.
        $konto->syncOriginal();

        return $konto;
    }

    /** This row, explicitly without a central counterpart. */
    public function fromLocalOnly(): self
    {
        $konto = clone $this;
        $konto->centrallyMerged = false;

        return $konto;
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
