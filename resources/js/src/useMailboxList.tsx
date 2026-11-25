import { useCallback, useRef, useState } from 'react';
import { merken, zuletztGesehen } from './zwischenspeicher';
import type { MessageHandle, RowMessage, Thread } from './rows';

/**
 * The list half of a mailbox: what is loaded, how it is paged, and what state
 * that leaves the view in.
 *
 * ## The addresses stay with the product, the sequence does not
 *
 * Where a product's mailbox endpoint lives is its business. What happens
 * around a load — clear the other mode's rows, remember the total, tell the
 * two kinds of failure apart, always stop the spinner — is the same
 * everywhere, and it is where the mistakes are.
 *
 * ## Flat and grouped are two lists, not one with a flag
 *
 * The server pages over conversations in one mode and over messages in the
 * other, so "page 3" means different things. Switching modes therefore starts
 * at the front, and the rows of the mode being left are cleared: otherwise the
 * list shows the previous mailbox's conversations while the new one loads.
 *
 * ## Two failures that must not be merged
 *
 * A server that answers "no" knows why and usually says so. A request that
 * never arrived knows nothing. Rendering both as one message robs the first of
 * its reason and gives the second one it does not have.
 */

export type MailboxFailure = { kind: 'server'; message?: string } | { kind: 'network' };

export interface MessagePage<M extends RowMessage> {
    messages: M[];
    threads: Thread<M>[];
    total: number;
    /**
     * How many rows a page holds. Only the endpoint knows it, and the
     * pagination in `MailListToolbar` needs it to say "3 of 7" — without it
     * every product would have to keep a copy of the server's page size and
     * keep it in step by hand. Absent, the last known value stands.
     */
    perPage?: number;
}

export interface ListParams {
    accountId: number | string;
    folder: string;
    page: number;
    /** Conversations instead of single messages. */
    grouped: boolean;
    /** Skip whatever cache the product keeps. */
    refresh: boolean;
}

export interface MailboxListSource<M extends RowMessage> {
    /**
     * Fetches one page. Throw to signal a network failure; return a failure
     * object to signal that the server answered and refused.
     */
    list(params: ListParams): Promise<MessagePage<M> | { failure: string | undefined }>;
}

export interface UseMailboxListOptions<M extends RowMessage> {
    source: MailboxListSource<M>;
    /** Conversations on or off to begin with. */
    initialGrouped?: boolean;
}

export interface MailboxList<M extends RowMessage> {
    messages: M[];
    threads: Thread<M>[];
    total: number;
    /** Rows per page, as the endpoint reports it. */
    perPage: number;
    /** Pages the current total spans — 0 while nothing is loaded. */
    totalPages: number;
    page: number;
    grouped: boolean;
    loading: boolean;
    refreshing: boolean;
    failure: MailboxFailure | null;
    /** Loads a page. Anything omitted keeps its current value. */
    load: (options?: Partial<ListParams> & { refresh?: boolean }) => Promise<void>;
    /** Reloads the current page, bypassing the product's cache. */
    refresh: (accountId: number | string, folder: string) => Promise<void>;
    /** Loads the same page again — the hook remembers what it last asked for. */
    reload: (options?: { refresh?: boolean }) => Promise<void>;
    /**
     * A message left the mailbox — moved, archived, deleted.
     *
     * In conversation mode the row lives in `threads`, where it cannot be
     * removed by uid: a conversation is a bundle, and taking one message out
     * of it changes what the row says about itself. So the list comes back
     * from the server. The Vue version removed from the flat list only, and a
     * conversation archived on its own stayed visible until a reload.
     */
    messageLeft: (uid: MessageHandle) => Promise<void>;
    /** The same for several at once — a bulk move, archive or delete. */
    messagesLeft: (uids: Iterable<MessageHandle>) => Promise<void>;
    /** Applies the same change to several rows, without reloading. */
    patchRows: (uids: Iterable<MessageHandle>, changes: Partial<M>) => void;
    /** Switches between conversations and single messages, starting at page 1. */
    setGrouped: (grouped: boolean, accountId: number | string, folder: string) => Promise<void>;
    /**
     * Changes one row wherever it sits — flat list, conversation head, or
     * conversation member. The unread dot of a conversation is recomputed
     * from its members, because that is what it means.
     */
    patchRow: (uid: MessageHandle, changes: Partial<M>) => void;
    /**
     * Changes a row found by message id rather than uid.
     *
     * A conversation row is a copy built while rendering; setting a field on
     * the object the view handed out changes nothing anyone sees. Anything
     * keyed by the message itself — an assignment, a link to a task — has to
     * go back in by id.
     */
    patchRowById: (messageId: string, changes: Partial<M>) => void;
    /** Drops rows the mailbox no longer holds, and corrects the total. */
    removeRows: (uids: Iterable<MessageHandle>) => void;
    /**
     * Shows rows that did not come from `source.list` — search results, most
     * often. Always flat: hits come from every folder, conversations only ever
     * from one, so they cannot be grouped.
     */
    showRows: (rows: M[], total: number) => void;
    /** Empties both lists — switching accounts, so the old one stops showing through. */
    clear: () => void;
    clearFailure: () => void;
    /**
     * Bis zu welcher Seite die Liste reicht — beim Weiterscrollen auf dem
     * Telefon wird angehaengt statt geblaettert (seit 25.11.2026).
     */
    loadedThrough: number;
    /** Gibt es hinter dem Angehaengten noch etwas? */
    hasMore: boolean;
    /** Haengt gerade die naechste Seite an. */
    loadingMore: boolean;
    /**
     * Die naechste Seite unten anhaengen.
     *
     * Bastian (25.11.2026): „Koennen wir die Mailliste auch vielleicht
     * infinite scroll machen?" Fuer das Telefon gedacht; am Schreibtisch
     * bleibt das Blaettern. Ein Neuladen derselben Seite (`refresh`, nach
     * Archivieren, Loeschen …) holt die angehaengten Seiten mit — sonst
     * schrumpfte die Liste nach jeder Aktion auf die erste Seite zurueck.
     */
    loadMore: () => Promise<void>;
}

/** Jede Zeile nur einmal — beim Anhaengen kann eine Mail zwischen zwei Seiten gerutscht sein. */
function einmal<T>(zeilen: T[], kennung: (zeile: T) => string): T[] {
    const gesehen = new Set<string>();

    return zeilen.filter((zeile) => {
        const k = kennung(zeile);

        if (gesehen.has(k)) {
            return false;
        }

        gesehen.add(k);

        return true;
    });
}

export function useMailboxList<M extends RowMessage>({ source, initialGrouped = false }: UseMailboxListOptions<M>): MailboxList<M> {
    const [messages, setMessages] = useState<M[]>([]);
    const [threads, setThreads] = useState<Thread<M>[]>([]);
    const [total, setTotal] = useState(0);
    const [perPage, setPerPage] = useState(25);
    const [page, setPage] = useState(1);
    const [grouped, setGroupedState] = useState(initialGrouped);
    const [loading, setLoading] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [failure, setFailure] = useState<MailboxFailure | null>(null);

    // The load in flight decides what is shown; a slower earlier one must not
    // overwrite it when it finally answers.
    const laufNr = useRef(0);

    // What the last load asked for, so a reload does not need it passed again.
    const zuletzt = useRef<ListParams | null>(null);

    // Bis wohin angehaengt ist (siehe `loadMore`). Als Ref fuer die Rueckrufe,
    // als Zustand fuer die Anzeige.
    const bisRef = useRef(1);
    const [bis, setBis] = useState(1);
    const mehrRef = useRef(false);
    const [loadingMore, setLoadingMore] = useState(false);

    const bisSetzen = (seite: number) => {
        bisRef.current = seite;
        setBis(seite);
    };

    const load = useCallback(
        async (options: Partial<ListParams> & { refresh?: boolean } = {}) => {
            const params: ListParams = {
                accountId: options.accountId ?? '',
                folder: options.folder ?? '',
                page: options.page ?? 1,
                grouped: options.grouped ?? grouped,
                refresh: options.refresh ?? false,
            };

            if (!params.accountId || !params.folder) {
                return;
            }

            const vorher = zuletzt.current;
            zuletzt.current = params;

            /*
             * Dieselbe Liste noch einmal (nach einer Aktion): die angehaengten
             * Seiten mitholen. Eine andere Seite, ein anderer Ordner — dann
             * beginnt das Anhaengen von vorn.
             */
            const mitHolen =
                params.refresh &&
                vorher !== null &&
                vorher.accountId === params.accountId &&
                vorher.folder === params.folder &&
                vorher.grouped === params.grouped &&
                vorher.page === params.page
                    ? bisRef.current
                    : params.page;

            const meiner = ++laufNr.current;

            /*
             * Was hier zuletzt stand, sofort zeigen — und still auffrischen
             * (siehe `zwischenspeicher`). Der Kreisel nur, wenn nichts bekannt
             * ist. Ein ausdrueckliches Aktualisieren (`refresh`) zeigt ihn
             * nicht, wenn schon Zeilen da sind: Es soll frisch werden, nicht
             * leer.
             */
            const schluessel = `liste.${params.accountId}.${params.folder}.${params.page}.${params.grouped ? 'k' : 'n'}`;
            const bekannt = params.refresh ? null : zuletztGesehen<{ messages: M[]; threads: Thread<M>[]; total: number; perPage?: number }>(schluessel);

            if (bekannt) {
                setMessages(bekannt.messages);
                setThreads(bekannt.threads);
                setTotal(bekannt.total);

                if (typeof bekannt.perPage === 'number' && bekannt.perPage > 0) {
                    setPerPage(bekannt.perPage);
                }

                setPage(params.page);
                setGroupedState(params.grouped);
                setLoading(false);
                bisSetzen(params.page);
            } else {
                setLoading(true);
            }

            setFailure(null);

            try {
                const result = await source.list(params);

                if (meiner !== laufNr.current) {
                    return;
                }

                if ('failure' in result) {
                    setFailure({ kind: 'server', message: result.failure });
                    return;
                }

                // Die angehaengten Seiten nachholen, der Reihe nach.
                let zeilen = result.messages;
                let ketten = result.threads;

                for (let weitere = params.page + 1; weitere <= mitHolen; weitere++) {
                    const naechste = await source.list({ ...params, page: weitere });

                    if (meiner !== laufNr.current) {
                        return;
                    }

                    if ('failure' in naechste) {
                        break;
                    }

                    zeilen = einmal([...zeilen, ...naechste.messages], (m) => String(m.uid));
                    ketten = einmal([...ketten, ...naechste.threads], (k) => k.thread_id);
                }

                // Only one of the two lists ever holds rows: the other mode's
                // leftovers would otherwise show through while this one loads.
                if (params.grouped) {
                    setThreads(ketten);
                    setMessages([]);
                } else {
                    setMessages(zeilen);
                    setThreads([]);
                }

                bisSetzen(Math.max(params.page, mitHolen));

                setTotal(result.total);

                if (typeof result.perPage === 'number' && result.perPage > 0) {
                    setPerPage(result.perPage);
                }

                setPage(params.page);
                setGroupedState(params.grouped);

                merken(schluessel, {
                    messages: params.grouped ? [] : result.messages,
                    threads: params.grouped ? result.threads : [],
                    total: result.total,
                    perPage: result.perPage,
                });
            } catch {
                if (meiner === laufNr.current) {
                    setFailure({ kind: 'network' });
                }
            } finally {
                if (meiner === laufNr.current) {
                    setLoading(false);
                    setRefreshing(false);
                }
            }
        },
        [grouped, source],
    );

    const refresh = useCallback(
        async (accountId: number | string, folder: string) => {
            setRefreshing(true);
            await load({ accountId, folder, page, grouped, refresh: true });
        },
        [load, page, grouped],
    );

    const setGrouped = useCallback(
        async (next: boolean, accountId: number | string, folder: string) => {
            // Page 3 of conversations is not page 3 of messages, so start over.
            await load({ accountId, folder, page: 1, grouped: next });
        },
        [load],
    );

    const reload = useCallback(
        async (options: { refresh?: boolean } = {}) => {
            if (!zuletzt.current) {
                return;
            }

            await load({ ...zuletzt.current, refresh: options.refresh ?? true });
        },
        [load],
    );

    const patchRow = useCallback((uid: MessageHandle, changes: Partial<M>) => {
        setMessages((before) => (before.some((m) => m.uid === uid) ? before.map((m) => (m.uid === uid ? { ...m, ...changes } : m)) : before));

        setThreads((before) =>
            before.map((thread) => {
                const inMembers = thread.messages.some((m) => m.uid === uid);
                const isHead = thread.latest?.uid === uid;

                if (!inMembers && !isHead) {
                    return thread;
                }

                const members = inMembers
                    ? thread.messages.map((m) => (m.uid === uid ? { ...m, ...changes } : m))
                    : thread.messages;

                return {
                    ...thread,
                    messages: members,
                    latest: isHead && thread.latest ? { ...thread.latest, ...changes } : thread.latest,
                    // The dot on a conversation row hangs on `has_unread`, so it
                    // has to follow what its members now say.
                    has_unread: inMembers ? members.some((m) => m.is_read === false) : thread.has_unread,
                };
            }),
        );
    }, []);

    const patchRows = useCallback(
        (uids: Iterable<MessageHandle>, changes: Partial<M>) => {
            for (const uid of uids) {
                patchRow(uid, changes);
            }
        },
        [patchRow],
    );

    const patchRowById = useCallback((messageId: string, changes: Partial<M>) => {
        setMessages((before) => before.map((m) => (m.message_id === messageId ? { ...m, ...changes } : m)));
        setThreads((before) =>
            before.map((thread) =>
                thread.latest?.message_id === messageId ? { ...thread, latest: { ...thread.latest, ...changes } } : thread,
            ),
        );
    }, []);

    const loadMore = useCallback(async () => {
        const params = zuletzt.current;
        const naechste = bisRef.current + 1;
        const seiten = perPage > 0 ? Math.ceil(total / perPage) : 0;

        if (!params || mehrRef.current || loading || naechste > seiten) {
            return;
        }

        // Kein eigener Lauf: Startet waehrenddessen ein neues Laden (anderer
        // Ordner, Aktualisieren), gehoert diese Antwort nicht mehr dazu.
        const meiner = laufNr.current;

        mehrRef.current = true;
        setLoadingMore(true);

        try {
            const result = await source.list({ ...params, page: naechste, refresh: false });

            if (meiner !== laufNr.current || 'failure' in result) {
                return;
            }

            if (params.grouped) {
                setThreads((vorher) => einmal([...vorher, ...result.threads], (k) => k.thread_id));
            } else {
                setMessages((vorher) => einmal([...vorher, ...result.messages], (m) => String(m.uid)));
            }

            setTotal(result.total);
            bisSetzen(naechste);
        } catch {
            // Still: Die Liste steht ja. Beim naechsten Scrollen wird es erneut versucht.
        } finally {
            mehrRef.current = false;
            setLoadingMore(false);
        }
    }, [loading, perPage, source, total]);

    const removeRows = useCallback((uids: Iterable<MessageHandle>) => {
        const weg = new Set(uids);

        setMessages((before) => {
            const danach = before.filter((m) => !weg.has(m.uid));

            setTotal((t) => Math.max(0, t - (before.length - danach.length)));

            return danach;
        });
    }, []);

    const showRows = useCallback((rows: M[], gesamt: number) => {
        // Not through `load`: these rows have no page and no folder behind them.
        // Bumping the run counter stops an in-flight load from overwriting them.
        laufNr.current++;
        setMessages(rows);
        setThreads([]);
        setTotal(gesamt);
        setPage(1);
        bisSetzen(1);
        setLoading(false);
        setRefreshing(false);
    }, []);

    const clear = useCallback(() => {
        laufNr.current++;
        setMessages([]);
        setThreads([]);
        setTotal(0);
        setPage(1);
    }, []);

    const messagesLeft = useCallback(
        async (uids: Iterable<MessageHandle>) => {
            if (grouped) {
                await reload({ refresh: true });
                return;
            }

            removeRows(uids);
        },
        [grouped, reload, removeRows],
    );

    const messageLeft = useCallback((uid: MessageHandle) => messagesLeft([uid]), [messagesLeft]);

    const clearFailure = useCallback(() => setFailure(null), []);

    return {
        messages, threads, total, perPage, totalPages: perPage > 0 ? Math.ceil(total / perPage) : 0, page, grouped, loading, refreshing, failure,
        load, refresh, reload, messageLeft, messagesLeft, setGrouped, patchRow, patchRows, patchRowById, removeRows, showRows, clear, clearFailure,
        loadedThrough: bis,
        hasMore: perPage > 0 && bis < Math.ceil(total / perPage),
        loadingMore,
        loadMore,
    };
}
