import { useCallback, useMemo, useState } from 'react';
import { bulkTargets } from './rows';
import type { RowMessage, Thread } from './rows';

/**
 * What is ticked, and which conversations are open.
 *
 * Two small sets that every mailbox list keeps, and one rule between them that
 * is easy to get wrong:
 *
 * **A tick on a conversation row means the conversation.** The checkbox carries
 * only the uid of its newest message, but nobody ticking a row with "3" next to
 * it means one of three. `targets` is therefore what an action must really act
 * on — and what a confirmation dialog must count, because a question that names
 * a smaller number than it deletes is not a question.
 *
 * In search the same tick means exactly one message: hits are single messages
 * from various folders, not conversations.
 */

export interface MessageSelection {
    /** The uids that were ticked — what the checkboxes show. */
    ticked: ReadonlySet<number>;
    /** What an action really hits. In conversation mode more than was ticked. */
    targets: number[];
    /** Whether anything is ticked at all. */
    any: boolean;
    /** Which conversations are expanded, by thread id. */
    expanded: ReadonlySet<string>;
    toggle: (uid: number, checked: boolean) => void;
    clear: () => void;
    toggleThread: (key: string) => void;
    collapseAll: () => void;
}

export interface UseMessageSelectionOptions<M extends RowMessage> {
    threads: Thread<M>[];
    grouped: boolean;
    isSearchMode: boolean;
}

export function useMessageSelection<M extends RowMessage>({
    threads,
    grouped,
    isSearchMode,
}: UseMessageSelectionOptions<M>): MessageSelection {
    const [ticked, setTicked] = useState<Set<number>>(new Set());
    const [expanded, setExpanded] = useState<Set<string>>(new Set());

    const targets = useMemo(
        () => bulkTargets({ selectedUids: ticked, threads, groupByThread: grouped, isSearchMode }),
        [ticked, threads, grouped, isSearchMode],
    );

    const toggle = useCallback((uid: number, checked: boolean) => {
        setTicked((before) => {
            const next = new Set(before);

            if (checked) {
                next.add(uid);
            } else {
                next.delete(uid);
            }

            return next;
        });
    }, []);

    const clear = useCallback(() => setTicked(new Set()), []);

    const toggleThread = useCallback((key: string) => {
        setExpanded((before) => {
            const next = new Set(before);

            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return next;
        });
    }, []);

    // Switching between conversations and single messages leaves the old keys
    // meaningless — they name conversations the new list does not have.
    const collapseAll = useCallback(() => setExpanded(new Set()), []);

    return { ticked, targets, any: ticked.size > 0, expanded, toggle, clear, toggleThread, collapseAll };
}
