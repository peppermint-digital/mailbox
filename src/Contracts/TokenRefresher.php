<?php

namespace Peppermint\Mailbox\Contracts;

use Peppermint\Mailbox\Models\MailAccount;

/**
 * Keeps an OAuth mailbox reachable.
 *
 * This is the one part of connecting that the package cannot do: which
 * provider, which client id, which endpoint — all of that is the application's
 * registration with Microsoft or Google, not the package's.
 *
 * What the package does insist on is WHEN: right before connecting, and only
 * when the token is about to expire. Refreshing on every connection would burn
 * a rotation per mailbox visit, and Microsoft rotates refresh tokens — a
 * needless refresh is a needless chance to lose the mailbox.
 */
interface TokenRefresher
{
    /**
     * Makes sure the account's access token is good for the next call.
     *
     * Implementations should write the new tokens back to the account. Throw
     * when the mailbox cannot be reached any more — a silent failure here
     * shows up much later as an authentication error nobody can place.
     */
    public function ensureFresh(MailAccount $account): void;
}
