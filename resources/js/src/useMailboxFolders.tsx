import { useCallback, useState } from 'react';
import { merken, zuletztGesehen } from './zwischenspeicher';
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
    /**
     * Ordner laden und sagen, welcher geoeffnet werden soll.
     *
     * `bevorzugt` schlaegt die Vorauswahl — aber nur, wenn es den Ordner in
     * der geladenen Liste gibt; sonst bleibt es bei
     * {@link preferredFolder}. Damit laeuft ein Verweis auf einen Ordner,
     * den es nicht mehr gibt, in den Posteingang statt in eine Fehlermeldung.
     */
    load: (accountId: number | string, options?: { refresh?: boolean; bevorzugt?: string | null }) => Promise<string | null>;
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

/** Der gewuenschte Ordner, wenn es ihn gibt — sonst die Vorauswahl (siehe `load`). */
function zielOrdner(ordner: MailFolder[], bevorzugt?: string | null): string | null {
    const gewuenscht = (bevorzugt ?? '').trim();

    if (gewuenscht !== '' && ordner.some((o) => o.path === gewuenscht)) {
        return gewuenscht;
    }

    return preferredFolder(ordner);
}

export function useMailboxFolders({ source }: { source: FolderSource }): MailboxFolders {
    const [folders, setFolders] = useState<MailFolder[]>([]);
    const [targets, setTargets] = useState<MailFolder[]>([]);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState<MailboxFailure | null>(null);

    const load = useCallback(
        async (accountId: number | string, options: { refresh?: boolean; bevorzugt?: string | null } = {}) => {
            if (!accountId) {
                return null;
            }

            const schluessel = `ordner.${accountId}`;

            /*
             * Bekannte Ordner sofort zeigen und den Zielordner aus ihnen
             * bestimmen — dann muss die Liste nicht auf die Ordner warten,
             * sondern startet gleich (siehe `zwischenspeicher`). Die frische
             * Ordnerliste kommt still hinterher.
             */
            const bekannt = options.refresh ? null : zuletztGesehen<MailFolder[]>(schluessel);

            if (bekannt && bekannt.length > 0) {
                setFolders(bekannt);
                setFailure(null);

                void source
                    .list({ accountId, refresh: false })
                    .then((frisch) => {
                        if (Array.isArray(frisch)) {
                            setFolders(frisch);
                            merken(schluessel, frisch);
                        }
                    })
                    .catch(() => {
                        // Still: Die bekannten Ordner stehen ja.
                    });

                return zielOrdner(bekannt, options.bevorzugt);
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
                merken(schluessel, result);

                /*
                 * Ein gewuenschter Ordner schlaegt die Vorauswahl — aber nur,
                 * wenn es ihn WIRKLICH gibt.
                 *
                 * Die Pruefung gehoert hierher und nicht zum Aufrufer: Die
                 * Ordnerliste liegt hier, und sie steht in `folders` erst
                 * nach dem naechsten Zeichnen. Ein Aufrufer koennte also nur
                 * raten oder ein zweites Mal laden.
                 *
                 * Ohne die Pruefung fuehrte ein Verweis auf einen Ordner, den
                 * es nicht mehr gibt (umbenannt, geloescht, anderes Postfach),
                 * in eine Fehlermeldung statt in den Posteingang. Ein
                 * verwaister Verweis soll die Seite nicht kaputtmachen,
                 * sondern dort landen, wo man ohne ihn gelandet waere.
                 */
                return zielOrdner(result, options.bevorzugt);
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
                    merken(`ordner.${accountId}`, result);
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
