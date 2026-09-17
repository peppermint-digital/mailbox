<?php

namespace Peppermint\Mailbox\Contracts;

use Peppermint\Mailbox\Search\Criteria;

/**
 * A mailbox, as a product uses one — independent of how it is reached.
 *
 * Everything above this interface already worked on plain rows: threading,
 * inline images, search merging, folder names, paging, the React views. Only
 * one class knew what IMAP is. Naming that seam makes a second way of reaching
 * a mailbox possible without touching anything above it.
 *
 * The second way is JMAP (RFC 8620/8621), which our own Stalwart speaks. It is
 * an addition, not a replacement: Office 365 and Google do not offer JMAP, so
 * IMAP stays the way most accounts are read.
 *
 * ## What every implementation owes the caller
 *
 * - **Folders are addressed by string.** Path or display name; the
 *   implementation resolves it. JMAP folders have ids — a product never sees
 *   them, or the product would have to know which transport it is talking to.
 * - **Rows come out in the shape the MessageFormatter produces.** That shape is
 *   the contract, not a byproduct of IMAP. Named here in prose on purpose: the
 *   contract must not import from the transport it describes.
 * - **A message that is gone is answered with `false` or `null`, not an
 *   exception.** A shared mailbox changes while someone is looking at it, and
 *   a colleague filing a mail must not turn into an error about a uid.
 *
 * ## Why the message handle is int|string
 *
 * IMAP identifies a message by a numeric uid, JMAP by an opaque string id
 * ("cqiaaaauc"). Forcing one into the other would mean inventing a mapping,
 * and an invented mapping is a thing that silently points at the wrong mail
 * after a rebuild. The handle travels: it comes out of a row and goes back in
 * unchanged. Products that route it through a URL must accept both.
 */
interface Mailbox
{
    /**
     * Runs several operations over one connection.
     *
     * Every single call connects and disconnects on its own — correct for a
     * web request, expensive for ten calls in a row. Work passed here shares
     * one connection; the callback is handed this same mailbox, never the
     * underlying driver object. Handing out the driver would tie the caller to
     * the transport, which is the thing this interface exists to prevent.
     *
     * Nesting is allowed and does not open a second connection.
     *
     * @template T
     *
     * @param  callable(self): T  $work
     * @return T
     */
    public function batch(callable $work): mixed;

    /**
     * The folders of this mailbox, as plain rows.
     *
     * @return list<array{name: string, path: string, flags: list<string>}>
     */
    public function folders(): array;

    /**
     * Creates the standard folders this mailbox is missing.
     *
     * Best effort: a server that refuses one is not a reason to skip the rest.
     *
     * @return list<string> the paths actually created
     */
    public function ensureStandardFolders(): array;

    public function createFolder(string $path): void;

    /**
     * Renames a folder, keeping it where it is.
     *
     * @return string the new path
     *
     * @throws \RuntimeException when the folder is gone or is a system folder
     */
    public function renameFolder(string $path, string $newName): string;

    /** @throws \RuntimeException when the folder is gone or is a system folder */
    public function deleteFolder(string $path): void;

    /**
     * Header rows of a folder, plus how many there are in total.
     *
     * Deliberately without bodies: sorting and cutting happen on these rows,
     * and only the visible page is formatted afterwards.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function headerRows(string $folder, int $limit = 100): array;

    /**
     * Search one folder.
     *
     * Rows in the same shape as {@see headerRows()}, newest first. The total
     * is what was found, not what the mailbox holds.
     *
     * An empty search is refused rather than answered: it would match every
     * message, and over IMAP that means dragging a whole folder across for a
     * search box someone tabbed through.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     *
     * @throws \InvalidArgumentException when the criteria are empty
     */
    public function search(string $folder, Criteria $criteria, int $limit = 50): array;

    /**
     * Search every folder worth searching.
     *
     * Trash, junk and drafts stay out — that is where things go that were
     * thrown away or never sent. Each row carries the `folder` it was found
     * in, because a hit without its folder cannot be opened.
     *
     * The third value is how many folders were actually searched. It is not
     * decoration: the mail browser shows it, and without it a search that
     * silently skipped half the mailbox looks like a complete one.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     *
     * @throws \InvalidArgumentException when the criteria are empty
     */
    public function searchAll(Criteria $criteria, int $limit = 50, int $perFolder = 10): array;

    /**
     * One page of conversations from a folder.
     *
     * Chains, not messages: paging over conversations is what a mail browser
     * shows, and paging over messages would tear a conversation across two
     * pages.
     *
     * `$ownReplies` is called once, with the thread keys of the rows that were
     * fetched, and answers with the product's own stored replies. A sent reply
     * lives in the product's database, not necessarily in the folder being
     * listed — and the package must not know that table. One query, not one
     * per chain.
     *
     * Put-aside chains are removed BEFORE paging, or the count says one thing
     * and the list shows another.
     *
     * @param  null|callable(list<string>): list<array<string, mixed>>  $ownReplies
     * @param  list<string>  $excludeThreadIds
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function threads(
        string $folder,
        int $page = 1,
        int $perPage = 25,
        ?callable $ownReplies = null,
        array $excludeThreadIds = [],
    ): array;

    /**
     * One message, in full. Null when it is not there any more.
     *
     * @return array<string, mixed>|null
     */
    public function message(string $folder, int|string $uid): ?array;

    /**
     * One attachment, with its bytes. Identified by position, not by name.
     *
     * @return array{filename: string, mime_type: string, contents: string}|null
     */
    public function attachment(string $folder, int|string $uid, int $index): ?array;

    /** False when the message is gone. */
    public function setSeen(string $folder, int|string $uid, bool $seen): bool;

    /** False when the message is gone. */
    public function setFlagged(string $folder, int|string $uid, bool $flagged): bool;

    /** Moving into the folder it already sits in is answered with true. */
    public function move(string $from, int|string $uid, string $to): bool;

    /**
     * Deletes a message — into the trash where there is one.
     *
     * The aliases are how IMAP finds the trash, where nothing but the name
     * says which folder it is. A transport that knows the folder's role uses
     * that and keeps the aliases as a fallback.
     *
     * @param  list<string>  $trashNames  lowercase aliases of the trash folder
     */
    public function delete(string $folder, int|string $uid, array $trashNames = ['trash', 'papierkorb', 'deleted items', 'gelöschte elemente']): bool;
}
