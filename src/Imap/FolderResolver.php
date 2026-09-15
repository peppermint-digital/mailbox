<?php

namespace Peppermint\Mailbox\Imap;

/**
 * Finding the folder that was meant.
 *
 * ## Why an exact path has to win
 *
 * IMAP encodes non-ASCII folder names in a modified UTF-7, so the German
 * drafts folder travels as `Entw&APw-rfe`. Servers do get into states where a
 * second, empty folder exists whose LITERAL name is that same string — a
 * phantom from a double encoding somewhere in its past.
 *
 * A lookup that matches loosely then has two candidates and picks whichever
 * comes first: the real folder with mail in it, or the empty phantom. Both
 * exist, neither lookup fails, and the mailbox simply looks empty.
 *
 * So: the path is what addresses a folder, and an exact path match wins. Only
 * when nothing matches exactly does the name get a turn — that case is a
 * caller who passed a display name, not a path.
 */
class FolderResolver
{
    /**
     * Picks the folder meant by `$wanted` from a list.
     *
     * @template T
     *
     * @param  iterable<T>  $folders
     * @param  callable(T): string  $pathOf
     * @param  callable(T): string  $nameOf
     * @return T|null
     */
    public static function resolve(iterable $folders, string $wanted, callable $pathOf, callable $nameOf): mixed
    {
        $list = is_array($folders) ? $folders : iterator_to_array($folders);

        foreach ($list as $folder) {
            if ($pathOf($folder) === $wanted) {
                return $folder;
            }
        }

        foreach ($list as $folder) {
            if ($nameOf($folder) === $wanted) {
                return $folder;
            }
        }

        return null;
    }
}
