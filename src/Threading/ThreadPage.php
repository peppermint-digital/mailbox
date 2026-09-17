<?php

namespace Peppermint\Mailbox\Threading;

/**
 * One page of conversations — the part that is the same on every transport.
 *
 * Grouping rows into chains is {@see ThreadGrouper}; this is what has to
 * happen around it, and it is where two mail browsers got it wrong before.
 *
 * ## Put-aside chains are removed BEFORE paging
 *
 * Someone puts a conversation aside; it must vanish from the list. Filtering
 * after the slice means the count says one thing and the list shows another —
 * on 07.08.2026 page one reported 24 and page two reported 25, from exactly
 * this.
 *
 * ## Only the visible page is formatted
 *
 * A hundred rows are fetched so the grouping has something to group. Turning a
 * row into a full summary touches its body — preview text, attachment list —
 * so formatting all hundred to show twenty-five is seventy-five body fetches
 * nobody asked for. The newest message of each VISIBLE chain gets the full
 * treatment; the rest stay headers.
 *
 * ## Why the product's own replies are handed in
 *
 * A sent reply lives in the product's database, not necessarily in the folder
 * being listed. The package cannot know that table — so the caller is asked,
 * once, with the keys of the chains actually on screen. One query, not one per
 * chain.
 */
final class ThreadPage
{
    /**
     * @param  list<array<string, mixed>>  $rows  header rows, newest first
     * @param  null|callable(list<string>): list<array<string, mixed>>  $ownReplies
     *                                                                               receives the thread keys of these rows, returns the product's
     *                                                                               own stored replies for them
     * @param  list<string>  $excludeThreadIds
     * @param  callable(array<string, mixed>): array<string, mixed>  $latest
     *                                                                        turns the newest row of a visible chain into a full summary
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public static function of(
        array $rows,
        ?callable $ownReplies,
        int $page,
        int $perPage,
        array $excludeThreadIds,
        callable $latest,
        ?ThreadGrouper $grouper = null,
    ): array {
        $grouper ??= new ThreadGrouper;

        $gespeicherte = [];

        if ($ownReplies !== null) {
            $schluessel = array_values(array_unique(array_map(
                fn (array $row): string => $grouper->keyFor($row),
                $rows,
            )));

            $gespeicherte = $ownReplies($schluessel);
        }

        $ketten = $grouper->group($rows, $gespeicherte);

        if ($excludeThreadIds !== []) {
            $ketten = array_values(array_filter(
                $ketten,
                fn (array $kette): bool => ! in_array($kette['thread_id'] ?? null, $excludeThreadIds, true),
            ));
        }

        $gesamt = count($ketten);
        $sichtbar = array_slice($ketten, max(0, ($page - 1) * $perPage), $perPage);

        foreach ($sichtbar as $i => $kette) {
            $nachrichten = $kette['messages'];
            $juengste = end($nachrichten);

            if ($juengste !== false) {
                $sichtbar[$i]['latest'] = $latest($juengste);
            }

            // Was der Transport intern mitgeschleppt hat, geht nicht nach
            // draussen — ein Produkt, das ein IMAP-Objekt in einer Zeile
            // findet, benutzt es irgendwann.
            $sichtbar[$i]['messages'] = array_map(
                fn (array $m): array => array_diff_key($m, ['message' => null]),
                $nachrichten,
            );
        }

        return [array_values($sichtbar), $gesamt];
    }
}
