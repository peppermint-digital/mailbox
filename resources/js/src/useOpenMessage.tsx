import { useCallback, useRef, useState } from 'react';
import { canOpenMessage, type RowMessage } from './rows';
import type { MailboxFailure } from './useMailboxList';

/**
 * The one message that is open.
 *
 * ## Which folder it came from is not obvious
 *
 * A search hit carries the folder it was found in, and that is the one to ask
 * with — not whichever folder the sidebar happens to show. But the header
 * index a product searches never cleans up, so a hit can point at a folder the
 * message left long ago. The server therefore answers with where it really
 * found it, and that answer wins over the guess.
 *
 * A message from the application's own archive has no folder at all; saying it
 * sits in one would make every later action address the mailbox for something
 * the mailbox does not hold.
 *
 * ## Opening marks it read
 *
 * Servers mark a message seen while delivering it. The list has to follow, or
 * the row keeps its unread dot until the next reload and people click it again
 * to "fix" it.
 */

export interface OpenedMessage<D> {
    message: D;
    /** Where the server really found it; null for something out of the archive. */
    folder: string | null;
}

export interface OpenMessageSource<M extends RowMessage, D> {
    /**
     * Fetches one message. `folder` is where the caller believes it sits —
     * the answer may say otherwise.
     */
    open(params: { message: M; folder: string }): Promise<OpenedMessage<D> | { failure: string | undefined }>;
}

export interface OpenMessage<M extends RowMessage, D> {
    message: D | null;
    /** Where the open message really sits; null for an archived one. */
    folder: string | null;
    loading: boolean;
    failure: MailboxFailure | null;
    /** True when the row carries no usable identifier at all. */
    unopenable: boolean;
    open: (message: M, folder: string) => Promise<OpenedMessage<D> | null>;
    close: () => void;
    /** Changes fields of the open message without fetching it again. */
    patch: (changes: Partial<D>) => void;
    clearFailure: () => void;
}

export function useOpenMessage<M extends RowMessage, D>({ source }: { source: OpenMessageSource<M, D> }): OpenMessage<M, D> {
    const [message, setMessage] = useState<D | null>(null);
    const [folder, setFolder] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState<MailboxFailure | null>(null);
    const [unopenable, setUnopenable] = useState(false);

    const laufNr = useRef(0);

    const open = useCallback(
        async (row: M, requestedFolder: string) => {
            // Without an identifier nothing may go to the mailbox: `UID FETCH 0`
            // is not "nothing happens", it is "message set is invalid".
            if (!canOpenMessage(row)) {
                setUnopenable(true);
                setLoading(false);

                return null;
            }

            const meiner = ++laufNr.current;

            setUnopenable(false);
            setLoading(true);
            setFailure(null);

            try {
                const result = await source.open({ message: row, folder: requestedFolder });

                if (meiner !== laufNr.current) {
                    return null;
                }

                if ('failure' in result) {
                    setFailure({ kind: 'server', message: result.failure });

                    return null;
                }

                setMessage(result.message);
                // A stored message has no folder; the server's answer beats the
                // folder the caller guessed.
                setFolder(row.stored_id ? null : (result.folder ?? requestedFolder));

                return result;
            } catch {
                if (meiner === laufNr.current) {
                    setFailure({ kind: 'network' });
                }

                return null;
            } finally {
                if (meiner === laufNr.current) {
                    setLoading(false);
                }
            }
        },
        [source],
    );

    const close = useCallback(() => {
        // A newer run number stops an answer still on its way from opening
        // something the person just closed.
        laufNr.current++;
        setMessage(null);
        setFolder(null);
        setUnopenable(false);
    }, []);

    const patch = useCallback((changes: Partial<D>) => {
        setMessage((before) => (before ? { ...before, ...changes } : before));
    }, []);

    const clearFailure = useCallback(() => setFailure(null), []);

    return { message, folder, loading, failure, unopenable, open, close, patch, clearFailure };
}
