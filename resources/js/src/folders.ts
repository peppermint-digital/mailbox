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
