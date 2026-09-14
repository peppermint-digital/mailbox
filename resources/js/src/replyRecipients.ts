/**
 * Who a reply goes to.
 *
 * Pure rules, no React and no network — which matters because getting them
 * wrong is expensive in a way tests can catch and reviewers cannot: a reply
 * that quietly goes to more people than intended.
 *
 * Three rules, and the third is the one that bites:
 *
 * 1. The sender of the original is always the recipient.
 * 2. "Reply all" adds everybody the original went to.
 * 3. **The own mailbox is removed** — otherwise every reply-all mails the
 *    account back to itself, and in a shared mailbox that means everyone reads
 *    their own reply as if it were new mail.
 */

export interface ReplyAddress {
    email: string;
    name: string;
}

export interface ReplySource {
    from_address: string;
    from_name: string | null;
    to_addresses?: ReplyAddress[] | null;
    cc_addresses?: ReplyAddress[] | null;
    /** The mailbox that received it — removed from the answer. */
    own_address: string;
}

function same(a: string, b: string): boolean {
    return a.toLowerCase() === b.toLowerCase();
}

/** The `To` of a reply. */
export function replyToRecipients(source: ReplySource, replyAll: boolean): ReplyAddress[] {
    const recipients: ReplyAddress[] = [{ email: source.from_address, name: source.from_name || '' }];

    if (!replyAll) {
        return recipients;
    }

    (source.to_addresses ?? []).forEach((address) => {
        const known = recipients.some((r) => same(r.email, address.email));

        if (!same(address.email, source.own_address) && !known) {
            recipients.push(address);
        }
    });

    return recipients;
}

/** The `Cc` of a reply — empty unless replying to all. */
export function replyCcRecipients(source: ReplySource, replyAll: boolean): ReplyAddress[] {
    if (!replyAll || !source.cc_addresses) {
        return [];
    }

    const inTo = replyToRecipients(source, replyAll).map((r) => r.email.toLowerCase());

    return source.cc_addresses.filter(
        (address) => !same(address.email, source.own_address) && !inTo.includes(address.email.toLowerCase()),
    );
}
