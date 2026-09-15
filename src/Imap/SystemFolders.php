<?php

namespace Peppermint\Mailbox\Imap;

/**
 * Which folders a person must not rename or delete.
 *
 * ## Why this is refused here and not left to the server
 *
 * The server refuses it anyway. Offering a button that the backend will reject
 * is worse than not offering one: the person tries, waits, and gets an error
 * in whatever words the server chose — usually English, usually about
 * something else.
 *
 * INBOX is protected unconditionally. It is the one folder every mailbox has,
 * and losing it loses the mailbox. The rest follows the server's own
 * special-use marks, which is the same thing the server locks.
 */
class SystemFolders
{
    /** @var list<string> */
    public const SPECIAL_USE = ['sent', 'drafts', 'trash', 'archive', 'junk', 'all', 'important', 'flagged'];

    /**
     * @param  list<string>  $flags the folder's IMAP flags
     */
    public static function isProtected(string $path, string $name, array $flags): bool
    {
        if (mb_strtoupper($path) === 'INBOX' || mb_strtoupper($name) === 'INBOX') {
            return true;
        }

        foreach ($flags as $flag) {
            $lower = mb_strtolower($flag);

            foreach (self::SPECIAL_USE as $mark) {
                if (str_contains($lower, $mark)) {
                    return true;
                }
            }
        }

        return false;
    }
}
