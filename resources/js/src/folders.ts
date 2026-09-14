/**
 * Folder rules for the browser half.
 *
 * The PHP side of this package judges folders by name (`Folders\FolderNames`);
 * this is the part a list view needs, and it asks the SERVER's answer first.
 *
 * IMAP marks its special folders with flags (`\\Sent`, `\\Trash`, …). Where a
 * server sets them, they are the truth; the name is only the fallback.
 */

export interface FolderLike {
    path: string;
    flags?: string[] | null;
}

/** The special-use marks that make a folder a system folder. */
const SPECIAL = ['sent', 'drafts', 'trash', 'archive', 'junk', 'all', 'important', 'flagged'];

/**
 * May this folder be renamed or deleted?
 *
 * INBOX never may — it is the one folder every mailbox has, and losing it
 * loses the mailbox. The rest follows the server's own marks, which mirrors
 * the lock the server enforces anyway: offering a button that the backend will
 * refuse is worse than not offering it.
 */
export function isProtectedFolder(folder: FolderLike): boolean {
    if (folder.path.toUpperCase() === 'INBOX') {
        return true;
    }

    const flags = (folder.flags ?? []).map((f) => f.toLowerCase());

    return SPECIAL.some((mark) => flags.some((flag) => flag.includes(mark)));
}

/**
 * The standard folders, and the names they hide behind.
 *
 * Mirrors `Folders\FolderNames::STANDARD` on the PHP side of this package —
 * the same table, because the same question gets asked in both halves.
 *
 * **The encoded spellings are not decoration.** IMAP encodes non-ASCII folder
 * names in a modified UTF-7 (RFC 3501): Office 365 reports the German drafts
 * as `Entw&APw-rfe` and the trash as `Gel&APY-schte Elemente`. A comparison
 * against "entwürfe" finds nothing there.
 */
export const STANDARD_FOLDER_ALIASES = {
    Sent: ['sent', 'sent items', 'sent mail', 'gesendet', 'gesendete objekte', 'gesendete elemente', 'gesendete&apy-', 'ges&apy-ndet'],
    Drafts: ['drafts', 'entwürfe', 'entwurf', 'entwurfe', 'entw&apw-rfe'],
    Trash: ['trash', 'deleted items', 'gelöschte elemente', 'geloschte elemente', 'papierkorb', 'gel&apy-schte'],
    Archive: ['archive', 'archiv', 'archived', 'archiviert', 'all mail', 'alle nachrichten'],
} as const satisfies Record<string, readonly string[]>;

export type StandardFolder = keyof typeof STANDARD_FOLDER_ALIASES;

/** The IMAP special-use flag that marks each standard folder. */
const FLAG_OF: Record<StandardFolder, string> = {
    Sent: 'sent',
    Drafts: 'drafts',
    Trash: 'trash',
    Archive: 'archive',
};

function containsAny(haystack: string, needles: readonly string[]): boolean {
    const lower = haystack.toLowerCase();

    return needles.some((needle) => needle !== '' && lower.includes(needle));
}

/**
 * Does this folder look like the given standard folder?
 *
 * **Flags first.** A server that sets `\Drafts` has answered the question, and
 * its answer beats any guess at the name. Only where the mark is missing does
 * the name decide — and then by the alias table, not by a hand-written pattern
 * that will miss the encoded spellings.
 *
 * Matches on substrings, on purpose: real folders are called `INBOX.Drafts`,
 * `[Gmail]/Entwürfe`, `Gelöschte Elemente`.
 */
export function looksLikeFolder(folder: FolderLike & { name?: string | null }, kind: StandardFolder): boolean {
    const flags = (folder.flags ?? []).map((flag) => flag.toLowerCase());

    if (flags.some((flag) => flag.includes(FLAG_OF[kind]))) {
        return true;
    }

    const aliases = STANDARD_FOLDER_ALIASES[kind];

    return containsAny(folder.path ?? '', aliases) || containsAny(folder.name ?? '', aliases);
}

/** Which standard folder this looks like, or null. */
export function classifyFolder(folder: FolderLike & { name?: string | null }): StandardFolder | null {
    const kinds = Object.keys(STANDARD_FOLDER_ALIASES) as StandardFolder[];

    return kinds.find((kind) => looksLikeFolder(folder, kind)) ?? null;
}
