<?php

namespace Peppermint\Mailbox\Contracts;

use Peppermint\Mailbox\Folders\FolderNames;
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
     * One page of a folder's message list — sorted, deduplicated, formatted.
     *
     * This is the verb a mail browser needs, and it exists because all three
     * products were assembling it by hand out of four package calls: fetch,
     * sort, dedupe in the sent folder, slice. Four chances to differ, and they
     * did.
     *
     * The order is the point. A hundred rows are fetched WITHOUT bodies so the
     * sort has something to sort; only the rows that end up visible are
     * formatted. Formatting touches the body, and over IMAP a body that was
     * not loaded is fetched on the spot — formatting all hundred to show
     * twenty-five is seventy-five round trips nobody asked for.
     *
     * The total is the smaller of what the server reports and what was
     * fetched: reporting the server's count offers pages that come back empty,
     * reporting the fetched count alone hides that there is more.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function page(string $folder, int $page = 1, int $perPage = 25): array;

    /**
     * Header rows of a folder, plus how many there are in total.
     *
     * The low-level verb: everything the folder holds in one go, formatted.
     * For a list view take {@see page()} instead — over IMAP this one formats
     * every row it fetched, and formatting is what touches the body.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function headerRows(string $folder, int $limit = 100): array;

    /**
     * Just the handles of everything in a folder — no headers, no bodies.
     *
     * The cheapest question a mailbox can answer, and the one an index needs
     * to find its own dead entries: everything it has stored for this folder
     * that is no longer there. One command, no content.
     *
     * An empty answer means an empty folder. It does NOT mean "delete
     * everything you know" — that distinction belongs to the caller, because
     * only the caller knows what it would be throwing away.
     *
     * @return list<int|string>
     */
    public function handles(string $folder): array;

    /**
     * Header rows of messages that arrived AFTER this one.
     *
     * For anything that keeps its own copy and wants only the growth since
     * last time — an index, a poller. Fetching the newest hundred and
     * discarding the known ones works too, and wastes the mailbox\'s time
     * every single run.
     *
     * The handle is a message handle like any other, not a number: IMAP can
     * answer this from its uid ordering, JMAP from the arrival time of that
     * message. What both promise is the same sentence — what came after this.
     *
     * @return list<array<string, mixed>>
     */
    public function newerThan(string $folder, int|string $handle, int $limit = 200): array;

    /**
     * Header rows of messages that arrived BEFORE this one, newest first.
     *
     * The other direction, and the reason it exists: an index that starts in
     * the middle of a full mailbox has to work backwards as well, or the old
     * mail stays invisible forever.
     *
     * @return list<array<string, mixed>>
     */
    public function olderThan(string $folder, int|string $handle, int $limit = 200): array;

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
     * The path of a standard folder, or null when this mailbox has none.
     *
     * `$kind` is one of the keys of {@see FolderNames::STANDARD}
     * — `Sent`, `Drafts`, `Trash`, `Archive`. The name is not the answer, only
     * the last resort: a server that marks its folders says which is which, in
     * every language and every encoding.
     */
    public function specialFolder(string $kind): ?string;

    /**
     * How many messages in this folder are newer than a point in time.
     *
     * `$scan` caps how far back to look and is not a detail: over IMAP this
     * walks the newest messages, and without a cap a quiet folder with fifty
     * thousand mails would be walked in full to answer "anything new?".
     * A count that stops at the cap is the honest answer to a cheap question.
     */
    public function countNewSince(string $folder, \DateTimeInterface $since, int $scan = 50): int;

    /**
     * Moves several messages into the archive folder.
     *
     * Counted, not reported one by one: the caller asked about a selection,
     * not about each mail. A message already in the archive counts as
     * archived — it is where it should be, and calling that a failure would
     * make a second click look broken.
     *
     * @param  list<int|string>  $uids
     * @return array{archived: int, failed: int}
     */
    public function archive(string $folder, array $uids): array;

    /**
     * Finds messages whose Message-ID carries this token.
     *
     * For probes: a product that files a copy of its own and wants to find it
     * again puts a unique token in the Message-ID. The search is a full-text
     * one because that is all IMAP offers — so every hit is checked against
     * the Message-ID afterwards, or a mail that merely quotes the token would
     * be taken for the probe.
     *
     * @return list<int|string>
     */
    public function findByToken(string $folder, string $token, int $limit = 20): array;

    /**
     * Can this mailbox be reached with these settings?
     *
     * Deliberately not a boolean: "no" without a reason sends a person to the
     * wrong field. The message is what the server said, not an interpretation
     * of it.
     *
     * @return array{ok: bool, message: string}
     */
    public function probe(): array;

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

    /**
     * Every attachment of a message, with its bytes.
     *
     * For forwarding: the original files have to be re-attached to the
     * outgoing mail, and asking for them one index at a time would fetch the
     * message once per file.
     *
     * Inline IMAGES stay out — they are part of the body and already embedded
     * in `body_html`. An inline PDF does not: it is a file someone attached,
     * whatever the disposition says, and a forward without it is incomplete.
     * That is a different rule than {@see attachment()} uses, which answers
     * the view's numbering; here nobody is counting, they are collecting.
     *
     * @return list<array{filename: string, mime_type: string|null, contents: string}>
     */
    public function attachments(string $folder, int|string $uid): array;

    /**
     * Puts a message into a folder, without sending it.
     *
     * The sent copy and the saved draft both need this: the mail exists as
     * text and has to end up in the mailbox, not on its way somewhere.
     *
     * Flags travel in IMAP spelling (`\Seen`, `\Draft`) because that is what
     * every caller already writes; a JMAP transport translates them into its
     * own keywords. Handing the caller two vocabularies for one thing would
     * put the transport back into the product.
     *
     * @param  string  $raw  the complete message, headers and body
     * @param  list<string>  $flags
     * @return int|string|null the new message's handle, when the server says
     *                         one — appending is worth doing even when it
     *                         does not
     */
    public function append(string $folder, string $raw, array $flags = []): int|string|null;

    /** False when the message is gone. */
    public function setSeen(string $folder, int|string $uid, bool $seen): bool;

    /** False when the message is gone. */
    public function setFlagged(string $folder, int|string $uid, bool $flagged): bool;

    /** Moving into the folder it already sits in is answered with true. */
    public function move(string $from, int|string $uid, string $to): bool;

    /**
     * Deletes a message for good — no trash, no second chance.
     *
     * The narrow case, and it has to be asked for by name: a draft that was
     * just replaced, a probe that did its job. {@see delete()} puts things
     * where someone can get them back, which is what "delete" means in a mail
     * client — but a trash folder slowly filling with every intermediate
     * version of a draft is not what anyone asked for either.
     *
     * False when the message is not there any more.
     */
    public function purge(string $folder, int|string $uid): bool;

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
