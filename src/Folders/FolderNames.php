<?php

namespace Peppermint\Mailbox\Folders;

/**
 * What a mailbox folder is, judged by its name.
 *
 * ## Why a name has to be judged at all
 *
 * IMAP has special-use flags (`\Trash`, `\Drafts`) and a server that sets them
 * answers the question properly. Many do not. What is left is the name — and
 * the name is whatever the mail provider felt like: English, German, with or
 * without a prefix, in a dialect of UTF-7 that looks like line noise.
 *
 * So: ask the flags first, fall back to these names. This class is the second
 * half, and it is the half that was copied around.
 *
 * ## The encoded spellings are not decoration
 *
 * IMAP encodes non-ASCII folder names in a modified UTF-7 (RFC 3501): Office
 * 365 reports the German trash as `Gel&APY-schte Elemente` and the drafts as
 * `Entw&APw-rfe`. A comparison against "gelöscht" finds nothing there. Measured
 * on a real mailbox in peppermint-manager: 10 of 50 search hits came out of
 * the trash before the encoded forms were included.
 */
class FolderNames
{
    /**
     * Folders a search must not return.
     *
     * Deleted mail, spam and half-written drafts are not results — a hit from
     * the trash is at best confusing and at worst a mail the user believed was
     * gone.
     *
     * @var list<string>
     */
    public const EXCLUDED_FROM_SEARCH = [
        'trash', 'papierkorb', 'deleted', 'junk', 'spam', 'draft',
        'gelöscht', 'geloscht', 'entwürfe', 'entwurfe',
        // IMAP modified UTF-7, the way Office 365 reports them:
        'gel&apy-schte', 'entw&apw-rfe',
    ];

    /**
     * The standard folders, and the names they hide behind.
     *
     * Key = the name to create when none exists, value = aliases for
     * recognising one that does (German and English, lowercase).
     *
     * @var array<string, list<string>>
     */
    public const STANDARD = [
        'Sent' => ['sent', 'sent items', 'sent mail', 'gesendet', 'gesendete objekte', 'gesendete elemente'],
        'Drafts' => ['drafts', 'entwürfe', 'entwurf'],
        'Trash' => ['trash', 'deleted items', 'gelöschte elemente', 'papierkorb'],
        'Archive' => ['archive', 'archiv', 'archived', 'archiviert', 'all mail', 'alle nachrichten'],
    ];

    /**
     * Does this path name a folder a search should leave alone?
     *
     * Matches on substrings, on purpose: real folders are called
     * `INBOX.Trash`, `[Gmail]/Papierkorb`, `Gelöschte Elemente`.
     */
    public static function isExcludedFromSearch(string $path): bool
    {
        return self::containsAny($path, self::EXCLUDED_FROM_SEARCH);
    }

    /**
     * Does this path look like the given standard folder?
     *
     * @param  string  $kind  one of the keys of {@see STANDARD}
     */
    public static function looksLike(string $path, string $kind): bool
    {
        return self::containsAny($path, self::STANDARD[$kind] ?? []);
    }

    /**
     * Which standard folder this path looks like, or null.
     */
    public static function classify(string $path): ?string
    {
        foreach (array_keys(self::STANDARD) as $kind) {
            if (self::looksLike($path, $kind)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $needles
     */
    private static function containsAny(string $path, array $needles): bool
    {
        $lower = mb_strtolower($path);

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
