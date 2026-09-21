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
    /** @var (\Closure(MailAccount): Mailbox)|null */
    private static ?\Closure $fake = null;

    /**
     * Substitutes the mailbox — for a product testing its own wiring.
     *
     * ## Why this exists although a container binding was rejected
     *
     * A binding would hide which transport was chosen, and in production that
     * is the one thing that must stay visible. This does the opposite: it is
     * explicit, it lives only in a test, and it has to be cleared again.
     *
     * What it makes testable is the part that breaks silently. A product
     * decides which verb to call — `search()` in one folder or `searchAll()`
     * across the mailbox — and if that decision is wrong, nothing fails: the
     * search simply looks in one place while someone believes it looked
     * everywhere. Without a seam that question needs a real mailbox, and a
     * question that needs a real mailbox does not get asked.
     *
     * Pass null to clear. A test that forgets leaves the next one testing a
     * ghost, so clear it in `afterEach`.
     *
     * @param  (\Closure(MailAccount): Mailbox)|null  $factory
     */
    public static function fake(?\Closure $factory): void
    {
        self::$fake = $factory;
    }

    /**
     * The mailbox for this account.
     *
     * Products that need to reach further — their own retry policy, their own
     * connector, a formatter of their own — build the client directly. This is
     * the ordinary way, not the only one.
     */
    public static function for(MailAccount $account, ?TokenRefresher $refresher = null): Mailbox
    {
        if (self::$fake !== null) {
            return (self::$fake)($account);
        }

        if ($account->usesJmap()) {
            return new JmapClient(account: $account, refresher: $refresher);
        }

        return new MailboxClient(account: $account, refresher: $refresher);
    }
}
