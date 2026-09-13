<?php

namespace Peppermint\Mailbox\Stores;

use Illuminate\Support\Collection;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Accounts live in the product's own table.
 *
 * The default, and the reason the package runs on its own: install it without
 * anything else and you get exactly this, with no trace of AI Brain.
 */
class LocalAccountStore implements AccountStore
{
    public function all(?int $ownerId = null): Collection
    {
        return MailAccount::query()
            ->when($ownerId !== null, fn ($query) => $query->where(MailAccount::column('user_id'), $ownerId))
            ->get();
    }

    public function find(string|int $id): ?MailAccount
    {
        return MailAccount::query()->find($id);
    }

    public function isWritable(): bool
    {
        return true;
    }
}
