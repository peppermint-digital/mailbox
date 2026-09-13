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
        // `accessibleBy`, not `where(user_id)`: a person's mailboxes include
        // the shared ones they may work in. Filtering on the owner alone hides
        // every team address — and hides it quietly, which is worse.
        return MailAccount::query()
            ->when($ownerId !== null, fn ($query) => $query->accessibleBy($ownerId))
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
