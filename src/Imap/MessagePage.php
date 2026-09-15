<?php

namespace Peppermint\Mailbox\Imap;

/**
 * Turning what a folder holds into the page a list shows.
 *
 * Pure arithmetic on rows, so the rules can be shown without a server — and
 * they are rules worth showing, because each one was paid for.
 *
 * ## UID order is not date order
 *
 * IMAP hands messages back in uid order, and a uid says when the server first
 * saw a message, not when it was sent. A mailbox that received an old mail
 * yesterday shows it at the top unless the rows are sorted by their Date
 * header.
 *
 * ## Headers first, bodies only for the page
 *
 * A hundred rows are fetched so the sort has something to sort. Formatting a
 * row touches its body — preview text, attachment list — so formatting all
 * hundred to display twenty-five means seventy-five body fetches nobody asked
 * for. Sort on headers, cut the page, then format.
 *
 * ## The sent folder holds everything twice
 *
 * Many servers file a copy on submission while the client appends its own. Two
 * rows, one mail, and the list looks broken. Deduplication goes by Message-ID
 * where there is one — and only where there is one, because the fallback key
 * is a guess and two different mails can match it.
 */
class MessagePage
{
    /** How many rows to pull before sorting. Beyond this a mailbox gets slow. */
    public const FETCH_LIMIT = 100;

    /**
     * Newest first, by the Date header rather than by uid.
     *
     * @param  list<array{date?: string|null}>  $rows
     * @return list<array<string, mixed>>
     */
    public static function sortByDateDesc(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            $links = ! empty($a['date']) ? strtotime($a['date']) : 0;
            $rechts = ! empty($b['date']) ? strtotime($b['date']) : 0;

            return $rechts <=> $links;
        });

        return array_values($rows);
    }

    /**
     * Drops the second copy of the same mail.
     *
     * By Message-ID, which is the only thing that really identifies a mail.
     * Without one, subject + date + sender have to do — a guess, and one that
     * can join two different mails, so it is used only when there is nothing
     * better.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function dedupe(array $rows): array
    {
        $gesehen = [];
        $ergebnis = [];

        foreach ($rows as $row) {
            $messageId = $row['message_id'] ?? null;

            $schluessel = $messageId !== null && $messageId !== ''
                ? 'mid:'.$messageId
                : 'ersatz:'.($row['subject'] ?? '').'|'.($row['date'] ?? '').'|'.($row['from_address'] ?? '');

            if (isset($gesehen[$schluessel])) {
                continue;
            }

            $gesehen[$schluessel] = true;
            $ergebnis[] = $row;
        }

        return $ergebnis;
    }

    /**
     * The rows of one page.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function slice(array $rows, int $page, int $perPage): array
    {
        return array_values(array_slice($rows, max(0, ($page - 1) * $perPage), $perPage));
    }

    /**
     * How many messages the list may claim to know about.
     *
     * The server's own count can be larger than what was fetched. Reporting it
     * would offer pages that come back empty; reporting the fetched count
     * alone would hide that there is more. The smaller of the two is the one
     * the list can actually deliver.
     */
    public static function effectiveTotal(int $serverTotal, int $fetched): int
    {
        return min($serverTotal, $fetched);
    }

    /**
     * Does this folder hold sent mail?
     *
     * Matters because only there is the duplicate filing to be expected.
     */
    public static function isSentFolder(string $path): bool
    {
        return FolderPaths::matchesAny($path, $path, [
            'sent', 'gesendet', 'sent items', 'sent mail', 'gesendete objekte', 'gesendete elemente',
        ]);
    }
}
