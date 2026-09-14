/**
 * Displaying addresses and senders.
 *
 * Small, but shared for the same reason as everything else here: the same
 * three lines existed in two pages, and a display rule that lives twice will
 * eventually read differently in two places on the same screen.
 */

export interface DisplayAddress {
    email: string;
    name?: string | null;
}

/** `Name <mail@example>`, or the bare address when there is no name. */
export function formatAddress(address: DisplayAddress): string {
    return address.name ? `${address.name} <${address.email}>` : address.email;
}

/** A comma-separated list, for a header line. */
export function formatAddressList(addresses: DisplayAddress[]): string {
    return addresses.map(formatAddress).join(', ');
}

/**
 * What a message list shows in the sender column.
 *
 * The fallback is passed in: "Unknown" is a word, and words belong to the
 * product.
 */
export function formatSender(message: { from_name?: string | null; from_address?: string | null }, unknown: string): string {
    return message.from_name || message.from_address || unknown;
}
