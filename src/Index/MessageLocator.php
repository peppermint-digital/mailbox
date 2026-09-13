<?php

namespace Peppermint\Mailbox\Index;

/**
 * Finds a message even when it is no longer where the header index says.
 *
 * ## Why this exists
 *
 * An index that only ever adds — growth, backfill, first run — and never
 * removes leaves a corpse behind every time a message leaves a folder: old
 * folder, old UID. And on a move the server assigns a NEW uid in the target;
 * the old one is dead.
 *
 * Where something archives continuously, a large share of search hits are such
 * corpses. To the user that looks like "search finds mails that will not open"
 * — and it hits other people's mail hardest, because the product's own sent
 * replies come from its database and never take this path.
 *
 * ## The walk
 *
 * 1. Look where the index says.
 * 2. If that comes up empty, the row is a corpse — **forget it**, so it does
 *    not come back next time.
 * 3. Try the other places the index knows for the same message, newest first,
 *    forgetting each dead one on the way.
 *
 * Deliberately NO server-wide search as a last resort: searching every folder
 * costs seconds on a large mailbox, and providers throttle per mailbox. If the
 * index does not know, "not found" is the honest answer — the next index run
 * makes it findable.
 *
 * ## Why it takes callbacks
 *
 * Fetching a message and forgetting an index row are the product's business:
 * its IMAP client, its table. The package contributes the walk and the rules —
 * newest first, forget as you go, never escalate to a full search.
 */
class MessageLocator
{
    /**
     * @param  callable(string, int): ?array<string, mixed>  $fetch  folder + uid → message, or null
     * @param  callable(string, int): void  $forget  drop this index row
     */
    public function __construct(
        private $fetch,
        private $forget,
    ) {}

    /**
     * @param  iterable<int, array{folder: string, uid: int}>  $alternatives  other places the
     *                                                                        index knows, newest first
     * @return array{folder: string, uid: int, message: array<string, mixed>}|null
     */
    public function locate(string $folder, int $uid, iterable $alternatives = []): ?array
    {
        $message = ($this->fetch)($folder, $uid);

        if ($message !== null) {
            return ['folder' => $folder, 'uid' => $uid, 'message' => $message];
        }

        ($this->forget)($folder, $uid);

        foreach ($alternatives as $candidate) {
            $otherFolder = (string) ($candidate['folder'] ?? '');
            $otherUid = (int) ($candidate['uid'] ?? 0);

            if ($otherFolder === '' || $otherUid <= 0 || ($otherFolder === $folder && $otherUid === $uid)) {
                continue;
            }

            $message = ($this->fetch)($otherFolder, $otherUid);

            if ($message !== null) {
                return ['folder' => $otherFolder, 'uid' => $otherUid, 'message' => $message];
            }

            // This one is dead too — take it with us.
            ($this->forget)($otherFolder, $otherUid);
        }

        return null;
    }
}
