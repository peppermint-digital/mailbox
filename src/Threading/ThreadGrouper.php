<?php

namespace Peppermint\Mailbox\Threading;

/**
 * Bundles messages into conversations.
 *
 * ## Rows in, rows out
 *
 * Deliberately without IMAP and without storage: the caller hands in finished
 * rows, this only sorts and bundles. That keeps grouping checkable without a
 * mailbox — the reply bugs in peppermint-manager showed where it leads when
 * logic is only reachable behind a network call.
 *
 * The same cut is what makes this shareable at all. A product's own replies
 * live in its database, so it maps them to rows and passes them in; the
 * package never learns what that table looks like.
 *
 * ## No user-facing text here
 *
 * A conversation without any subject gets `null`, not a label. What a reader
 * should see instead is the product's decision — and its language.
 */
class ThreadGrouper
{
    /**
     * Bundle message rows into chains, newest chain first.
     *
     * @param  array<int, array<string, mixed>>  $rows  rows from the mailbox (headers are enough)
     * @param  array<int, array<string, mixed>>  $storedOutbound  the product's own stored replies,
     *                                                            each with a `thread_id` or a `subject`
     * @return array<int, array<string, mixed>>
     */
    public function group(array $rows, array $storedOutbound = []): array
    {
        $threads = [];

        foreach ($rows as $row) {
            $threads[$this->keyFor($row)]['messages'][] = $row + ['source' => 'mailbox'];
        }

        // Own replies: they are in the product's database because the mailbox
        // only carries them in the sent folder. Without them every chain would
        // consist of other people's messages with gaps in between.
        foreach ($storedOutbound as $reply) {
            $key = $reply['thread_id'] ?? null;

            if ($key === null || $key === '') {
                $key = ThreadKey::fallbackFromSubject($reply['subject'] ?? null);
            }

            if ($key === null) {
                continue;
            }

            // Only attach to chains that are already visible. A stored reply
            // does not open a chain of its own — otherwise conversations would
            // appear in the inbox that hold nothing from the inbox.
            if (! isset($threads[$key])) {
                continue;
            }

            $threads[$key]['messages'][] = $reply + ['source' => 'stored', 'direction' => 'outbound', 'is_read' => true];
        }

        return $this->finish($threads);
    }

    /**
     * The key of a row: from the headers, else from the subject, else its own.
     *
     * @param  array<string, mixed>  $row
     */
    public function keyFor(array $row): string
    {
        $key = ThreadKey::fromHeaders(
            $row['message_id'] ?? null,
            $row['in_reply_to'] ?? null,
            $row['references'] ?? null,
        );

        return $key
            ?? ThreadKey::fallbackFromSubject($row['subject'] ?? null)
            ?? 'single:'.($row['uid'] ?? spl_object_id((object) $row));
    }

    /**
     * Finish the chains: messages in chronological order, chains by their
     * newest message descending — the way a mailbox list has to feel.
     *
     * @param  array<string, array{messages: array<int, array<string, mixed>>}>  $threads
     * @return array<int, array<string, mixed>>
     */
    private function finish(array $threads): array
    {
        $result = [];

        foreach ($threads as $key => $thread) {
            $messages = $thread['messages'];

            usort($messages, fn ($a, $b) => $this->timeOf($a) <=> $this->timeOf($b));

            $newest = end($messages) ?: [];
            $oldest = $messages[0] ?? [];

            $result[] = [
                'thread_id' => $key,
                // A conversation is named after its beginning — later
                // "Re:"/"AW:" stacks and subject changes do not make it a
                // different matter.
                'subject' => $oldest['subject'] ?? ($newest['subject'] ?? null),
                'message_count' => count($messages),
                'latest_date' => $newest['date'] ?? null,
                'has_unread' => $this->any($messages, fn (array $m): bool => ($m['is_read'] ?? true) === false),
                'has_attachments' => $this->any($messages, fn (array $m): bool => (bool) ($m['has_attachments'] ?? false)),
                'messages' => $messages,
            ];
        }

        usort($result, fn ($a, $b) => $this->timeFrom($b['latest_date']) <=> $this->timeFrom($a['latest_date']));

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  callable(array<string, mixed>): bool  $question
     */
    private function any(array $messages, callable $question): bool
    {
        foreach ($messages as $message) {
            if ($question($message)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $message */
    private function timeOf(array $message): int
    {
        return $this->timeFrom($message['date'] ?? null);
    }

    private function timeFrom(mixed $date): int
    {
        return is_string($date) && $date !== '' ? (strtotime($date) ?: 0) : 0;
    }
}
