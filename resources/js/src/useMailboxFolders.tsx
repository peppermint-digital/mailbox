import { useCallback, useState } from 'react';
import type { MailFolder } from './MailFolderList';
import type { MailboxFailure } from './useMailboxList';

/**
 * The folder half of a mailbox.
 *
 * Three different ways of asking for folders live here, and they differ in
 * what they do when the answer does not come:
 *
 * - `load` — the one the person is waiting for. Failures are reported.
 * - `refreshQuietly` — after renaming or deleting a folder. A failure here
 *   leaves the old list standing, which is right: the action itself already
 *   said whether it worked, and a second error message about the refresh
 *   would blame the wrong thing.
 * - `loadTargetsOnce` — the folder list behind a "move to…" menu. Asked for
 *   once and kept; a failure leaves the menu empty rather than blocking it.
 */

/**
 * Which folder to open when a mailbox is first shown.
 *
 * INBOX, if it is there — it is the one folder every mailbox has and where
 * mail arrives. Only otherwise the first of the list, which is whatever the
 * server happened to sort to the top; opening a mailbox into "Archiv" because
 * A comes first is not a default, it is an accident.
 */
export function preferredFolder(folders: MailFolder[]): string | null {
    if (folders.length === 0) {
        return null;
    }

    const inbox = folders.find((folder) => folder.path.toUpperCase() === 'INBOX');

    return inbox?.path ?? folders[0]?.path ?? null;
}

export interface FolderSource {
    /** The folders shown in the sidebar. Return a failure object for a refusal. */
    list(params: { accountId: number | string; refresh: boolean }): Promise<MailFolder[] | { failure: string | undefined }>;
    /** Every folder a message may be moved into — often more than the sidebar shows. */
    listTargets?(params: { accountId: number | string }): Promise<MailFolder[]>;
}

export interface MailboxFolders {
    folders: MailFolder[];
    /** Every folder a message may be moved into; empty until asked for. */
    targets: MailFolder[];
    loading: boolean;
    failure: MailboxFailure | null;
    /**
     * Loads the sidebar and returns the folder that should be opened —
     * the caller loads its messages, so the two halves stay independent.
     */
    load: (accountId: number | string, options?: { refresh?: boolean }) => Promise<string | null>;
    /** Updates the list without reporting failures. */
    refreshQuietly: (accountId: number | string) => Promise<void>;
    /** Loads the move targets once and keeps them. */
    loadTargetsOnce: (accountId: number | string) => Promise<void>;
    /**
     * Empties the lists — when the account changes.
     *
     * A failed load deliberately keeps what was there, because an emptied list
     * and a broken connection look alike. That reasoning stops at the account
     * boundary: folders of the PREVIOUS mailbox shown under the name of the new
     * one do not say "we could not load", they say something false.
     */
    clear: () => void;
    clearFailure: () => void;
}

export function useMailboxFolders({ source }: { source: FolderSource }): MailboxFolders {
    const [folders, setFolders] = useState<MailFolder[]>([]);
    const [targets, setTargets] = useState<MailFolder[]>([]);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState<MailboxFailure | null>(null);

    const load = useCallback(
        async (accountId: number | string, options: { refresh?: boolean } = {}) => {
            if (!accountId) {
                return null;
            }

            setLoading(true);
            setFailure(null);

            try {
                const result = await source.list({ accountId, refresh: options.refresh ?? false });

                if (!Array.isArray(result)) {
                    setFailure({ kind: 'server', message: result.failure });
                    return null;
                }

                setFolders(result);

                return preferredFolder(result);
            } catch {
                setFailure({ kind: 'network' });
                return null;
            } finally {
                setLoading(false);
            }
        },
        [source],
    );

    const refreshQuietly = useCallback(
        async (accountId: number | string) => {
            if (!accountId) {
                return;
            }

            try {
                const result = await source.list({ accountId, refresh: true });

                if (Array.isArray(result)) {
                    setFolders(result);
                }
            } catch {
                // Deliberately silent: the action that triggered this already
                // reported its own outcome.
            }
        },
        [source],
    );

    const loadTargetsOnce = useCallback(
        async (accountId: number | string) => {
            if (!accountId || targets.length > 0 || !source.listTargets) {
                return;
            }

            try {
                setTargets(await source.listTargets({ accountId }));
            } catch {
                // An empty menu is better than a blocked one.
            }
        },
        [source, targets.length],
    );

    const clear = useCallback(() => {
        setFolders([]);
        setTargets([]);
        setFailure(null);
    }, []);

    const clearFailure = useCallback(() => setFailure(null), []);

    return { folders, targets, loading, failure, load, refreshQuietly, loadTargetsOnce, clear, clearFailure };
}
