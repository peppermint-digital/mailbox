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
     * What the trash folder may be called.
     *
     * ## Why this list is a data-loss boundary, not a convenience
     *
     * {@see \Peppermint\Mailbox\Contracts\Mailbox::delete()} moves a message
     * into the trash — and when it finds no trash, it deletes the message for
     * good. That fallback is intended for a mailbox that genuinely has no
     * trash folder. It cannot tell that case apart from "there is one and I
     * failed to recognise its name".
     *
     * So a missing spelling here does not degrade gracefully. It turns the
     * delete button into permanent deletion, once, silently, on somebody's
     * mail. That is the reason this list is long and lives in one place.
     *
     * ## Why the encoded spellings are in it
     *
     * Same reason as {@see EXCLUDED_FROM_SEARCH}: over IMAP a non-ASCII folder
     * name arrives in modified UTF-7 (RFC 3501), and Office 365 reports the
     * German trash as `Gel&APY-schte Elemente`. A comparison against
     * "gelöschte elemente" does not find it. In the search path that cost was
     * measured — 10 of 50 hits came out of the trash — and the encoded forms
     * fixed it. Here the cost would be higher than a confusing hit.
     *
     * An alias that never matches anything is free. A missing one is not.
     *
     * `deleted` and `gelöscht` come from peppermint-manager, which passed them
     * by hand because its mailboxes needed them; carrying them here is what
     * makes that hand-off unnecessary.
     *
     * @var list<string>
     */
    public const TRASH_ALIASES = [
        'trash', 'papierkorb',
        'deleted', 'deleted items', 'deleted messages',
        'gelöscht', 'geloscht', 'gelöschte elemente', 'geloschte elemente',
        // IMAP modified UTF-7, the way Office 365 reports them:
        'gel&apy-schte elemente', 'gel&apy-scht',
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
     * Der beste Namenstreffer unter mehreren — unabhaengig von der Reihenfolge.
     *
     * ## Warum es diese Methode gibt
     *
     * Beide Uebertragungswege hatten dieselbe Zeile: `if ($treffer === null &&
     * looksLike(...)) { $treffer = $pfad; }` — der ZUERST gelieferte Treffer
     * gewann. Da `looksLike` per Teilzeichenkette vergleicht, passt auf
     * „Archive" auch `Archives/2024`. Welcher Ordner gewinnt, haengt damit
     * allein an der Reihenfolge, in der der Server seine Ordner nennt — und
     * die ist nicht zugesichert.
     *
     * Am 14.10.2026 an info@crewtex.de gemessen: Vor dem Zusammenlegen zweier
     * Archiv-Ordner kam `Archives` zuerst, danach `Archives/2024`. Dieselbe
     * Anfrage, dieselbe Fassung, anderes Ziel — ohne dass jemand etwas am Code
     * geaendert haette.
     *
     * ## Die Regel
     *
     * Der KUERZESTE Pfad gewinnt, bei Gleichstand alphabetisch. Ein
     * Elternordner ist immer ein echtes Praefix seiner Kinder und damit
     * kuerzer — `Archives` schlaegt `Archives/2024`, ohne dass diese Methode
     * das Trennzeichen des Servers kennen muss. Und das Ergebnis ist
     * reproduzierbar.
     *
     * @param  iterable<string>  $paths
     */
    public static function bestMatch(iterable $paths, string $kind): ?string
    {
        $treffer = [];

        foreach ($paths as $pfad) {
            if (self::looksLike($pfad, $kind)) {
                $treffer[] = $pfad;
            }
        }

        if ($treffer === []) {
            return null;
        }

        usort($treffer, function (string $a, string $b): int {
            return [mb_strlen($a), $a] <=> [mb_strlen($b), $b];
        });

        return $treffer[0];
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
     * Ordner, die niemand umbenennen oder loeschen darf.
     *
     * Der Posteingang und die vier Standardordner. Das Postfach haengt an
     * ihnen: Wer `Sent` loescht, verliert nicht nur einen Ordner, sondern den
     * Ort, an dem kuenftig Gesendetes landet — und der Fehler faellt erst
     * Wochen spaeter auf.
     *
     * ## Warum das hier steht und nicht nur in der Oberflaeche
     *
     * Bis zum 22.09.2026 gab es diese Pruefung NUR im Browser
     * (`isProtectedFolder` in `folders.ts`). Sie blendete das Kontextmenue
     * aus — und das war alles. Ein Aufruf des Endpunkts von Hand haette den
     * Posteingang geloescht, ohne dass irgendwo etwas widersprochen haette.
     *
     * Eine Regel, die nur die Maske kennt, ist keine Regel. Sie ist eine
     * Bitte.
     *
     * @param  list<string>  $flags  IMAP-Marken des Ordners, falls bekannt
     */
    public static function isProtected(string $path, array $flags = []): bool
    {
        if (mb_strtoupper(trim($path)) === 'INBOX') {
            return true;
        }

        // Der Server sagt selbst, welcher Ordner welcher ist — in jeder
        // Sprache und jeder Kodierung. Das schlaegt jede Namensliste.
        foreach ($flags as $marke) {
            $marke = mb_strtolower((string) $marke);

            foreach (['sent', 'drafts', 'draft', 'trash', 'junk', 'archive', 'all', 'flagged', 'important'] as $bekannt) {
                if (str_contains($marke, $bekannt)) {
                    return true;
                }
            }
        }

        return self::classify($path) !== null;
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
