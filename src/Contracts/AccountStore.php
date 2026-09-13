<?php

namespace Peppermint\Mailbox\Contracts;

use Illuminate\Support\Collection;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Where mailbox settings come from.
 *
 * ## Why this is a contract and not a table
 *
 * A package cannot be the store — it can only decide *where* storing happens.
 * If the package held the settings itself, every consuming product would get
 * its own table with its own rows: the same mailbox would exist in three
 * places with three passwords. The duplicated code would be gone, the
 * duplicated truth worse than before.
 *
 * ## The two implementations
 *
 * - {@see \Peppermint\Mailbox\Stores\LocalAccountStore} — own table. The
 *   default; runs standalone.
 * - {@see \Peppermint\Mailbox\Stores\BrainAccountStore} — read centrally from
 *   AI Brain, cached locally on the last known good state.
 *
 * The Brain store is an offer, never a requirement: this package is meant to
 * run open source and on its own. A package that forces a dependency forces it
 * on everyone.
 */
interface AccountStore
{
    /**
     * The accounts this context is allowed to see.
     *
     * @return Collection<int, MailAccount>
     */
    public function all(?int $ownerId = null): Collection;

    /**
     * A single account, or null when there is none.
     *
     * Never throws when the remote is unreachable: the caller gets the last
     * known good state or nothing, and the screen still renders. A mail browser
     * that refuses to appear because a second system is down is worse than one
     * showing a stale account.
     */
    public function find(string|int $id): ?MailAccount;

    /**
     * Does this store accept writes?
     *
     * A central store is read-only from the product's point of view: changes
     * belong where the truth lives. The UI needs to know, so it does not offer
     * a form whose save button leads nowhere.
     */
    public function isWritable(): bool;
}
