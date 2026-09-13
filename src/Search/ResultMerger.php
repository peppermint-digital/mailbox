<?php

namespace Peppermint\Mailbox\Search;

use Peppermint\Mailbox\Threading\ThreadKey;

/**
 * Merges search hits from the mailbox and from the product's own archive into
 * one list.
 *
 * ## Why two sources at all
 *
 * They have different gaps. The mailbox only knows what is still on the
 * server; the archive also knows what was filed against a task or sent by the
 * product itself — but not the whole stock. Searching one of them alone
 * quietly misses half the answer.
 *
 * ## Rows in, rows out
 *
 * No IMAP, no database: the caller hands in finished lists. That keeps merging
 * checkable without a mailbox, and it is what makes this shareable — the
 * archive is a product's own table, so the product maps it to rows.
 */
class ResultMerger
{
    /**
     * @param  array<int, array<string, mixed>>  $mailboxHits
     * @param  array<int, array<string, mixed>>  $storedHits
     * @return array<int, array<string, mixed>> newest first
     */
    public function merge(array $mailboxHits, array $storedHits = [], int $limit = 50): array
    {
        $byKey = [];

        foreach ($mailboxHits as $hit) {
            $byKey[$this->keyFor($hit)] = $hit + ['source' => 'mailbox'];
        }

        foreach ($storedHits as $stored) {
            $key = $this->keyFor($stored);

            // The same message can sit in both sources. The mailbox hit wins:
            // only it can be opened, because only it has a UID. The stored id
            // travels along so the caller keeps the reference.
            if (isset($byKey[$key])) {
                $byKey[$key]['also_stored_id'] = $stored['stored_id'] ?? null;

                continue;
            }

            $byKey[$key] = $stored + ['source' => 'stored', 'is_read' => true, 'folder' => null];
        }

        $result = array_values($byKey);

        usort($result, fn ($a, $b) => $this->timeFrom($b['date'] ?? null) <=> $this->timeFrom($a['date'] ?? null));

        return array_slice($result, 0, $limit);
    }

    /**
     * The de-duplication key: the message-id, normalised.
     *
     * Without one, subject plus timestamp has to do — better than showing
     * every hit twice, and worse than nothing only in the rare case where two
     * different mails carry the same subject in the same second.
     *
     * @param  array<string, mixed>  $hit
     */
    private function keyFor(array $hit): string
    {
        $id = ThreadKey::normalize($hit['message_id'] ?? null);

        if ($id !== null) {
            return 'id:'.$id;
        }

        return 'fallback:'.mb_strtolower((string) ($hit['subject'] ?? '')).'|'.((string) ($hit['date'] ?? ''));
    }

    private function timeFrom(mixed $date): int
    {
        return is_string($date) && $date !== '' ? (strtotime($date) ?: 0) : 0;
    }
}
