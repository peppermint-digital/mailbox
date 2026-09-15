<?php

namespace Peppermint\Mailbox\Imap;

use Peppermint\Mailbox\Folders\FolderNames;

/**
 * Where a folder sits, and where a new one has to go.
 *
 * Pure path arithmetic, kept away from the connection so every rule here can
 * be shown without a server.
 *
 * ## Not every mailbox is flat
 *
 * Some servers keep everything below the inbox: the sent folder is not `Sent`
 * but `INBOX.Sent`, and creating `Sent` at the top either fails or produces a
 * second folder nobody sees. The prefix — including its delimiter, which is
 * `.` on some servers and `/` on others — has to be read off the mailbox
 * itself rather than assumed.
 */
class FolderPaths
{
    /**
     * The prefix under which this mailbox keeps its folders, if it uses one.
     *
     * Read from an existing path rather than guessed: a mailbox that has
     * `INBOX.Sent` will want `INBOX.Archive`, one that has `Sent` will not.
     *
     * @param  iterable<string>  $paths
     * @return string|null e.g. `INBOX.` — including the delimiter
     */
    public static function inboxPrefix(iterable $paths): ?string
    {
        foreach ($paths as $path) {
            if (preg_match('/^INBOX(.)/', $path, $treffer) === 1) {
                return 'INBOX'.$treffer[1];
            }
        }

        return null;
    }

    /**
     * Where to try creating a standard folder, best guess first.
     *
     * Both orders are offered because a mailbox can answer either way, and a
     * refused create is cheap while a folder in the wrong place is not.
     *
     * @return list<string>
     */
    public static function standardCandidates(string $name, ?string $inboxPrefix): array
    {
        return $inboxPrefix ? [$inboxPrefix.$name, $name] : [$name, 'INBOX.'.$name];
    }

    /**
     * The path a folder gets when it is renamed.
     *
     * Only the last segment changes. Renaming `Projekte/2026` to `2027` must
     * give `Projekte/2027` — a bare `2027` would not rename the folder but
     * move it to the top level, quietly, and its mail with it.
     */
    public static function renamed(string $currentPath, string $newName, string $delimiter = '/'): string
    {
        $parts = explode($delimiter, $currentPath);
        array_pop($parts);
        $parts[] = $newName;

        return implode($delimiter, $parts);
    }

    /**
     * Does this folder answer to one of the given names?
     *
     * Matches the name, the whole path, or the last segment of the path —
     * `INBOX.Sent` is the sent folder, and so is `[Gmail]/Sent`.
     *
     * @param  list<string>  $aliases lowercase
     */
    public static function matchesAny(string $name, string $path, array $aliases): bool
    {
        $name = mb_strtolower($name);
        $path = mb_strtolower($path);

        foreach ($aliases as $alias) {
            if ($name === $alias || $path === $alias || str_ends_with($path, '.'.$alias) || str_ends_with($path, '/'.$alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which standard folders are missing from this mailbox.
     *
     * A fresh mailbox often has nothing but INBOX. Without a sent folder the
     * application cannot file what it sends; without an archive it cannot
     * archive. Both fail later, far from the cause.
     *
     * @param  iterable<array{name: string, path: string}>  $existing
     * @return list<string> the keys of {@see FolderNames::STANDARD}
     */
    public static function missingStandardFolders(iterable $existing): array
    {
        $vorhanden = is_array($existing) ? $existing : iterator_to_array($existing);
        $fehlend = [];

        foreach (FolderNames::STANDARD as $kind => $aliases) {
            $da = false;

            foreach ($vorhanden as $folder) {
                if (self::matchesAny($folder['name'], $folder['path'], $aliases)) {
                    $da = true;
                    break;
                }
            }

            if (! $da) {
                $fehlend[] = $kind;
            }
        }

        return $fehlend;
    }
}
