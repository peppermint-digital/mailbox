<?php

namespace Peppermint\Mailbox;

use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Contracts\TokenRefresher;
use Peppermint\Mailbox\Imap\MailboxClient;
use Peppermint\Mailbox\Jmap\JmapClient;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Hands out the right transport for a mailbox.
 *
 * One line, and it is the only place in the system that decides between IMAP
 * and JMAP. A product asks for a mailbox and gets one; which protocol it
 * speaks is a property of the account, not of the calling code.
 *
 * ## Why the decision does not live in configuration
 *
 * A setting would be per application: this app reads JMAP, that one IMAP. But
 * the same three products read the SAME mailboxes, and one of those mailboxes
 * sits on our own Stalwart while the others are at Microsoft. The protocol
 * belongs to the mailbox, travels with it from the central store, and switches
 * for everyone at once when the field changes.
 *
 * ## Why this is not a container binding
 *
 * There is no single mailbox to resolve. Every call needs the account, so a
 * binding would be a factory with extra steps — and one that hides which
 * transport was chosen.
 */
final class Mailboxes
{
    /**
     * The mailbox for this account.
     *
     * Products that need to reach further — their own retry policy, their own
     * connector, a formatter of their own — build the client directly. This is
     * the ordinary way, not the only one.
     */
    public static function for(MailAccount $account, ?TokenRefresher $refresher = null): Mailbox
    {
        if ($account->usesJmap()) {
            return new JmapClient(account: $account, refresher: $refresher);
        }

        return new MailboxClient(account: $account, refresher: $refresher);
    }
}
