/**
 * Turning what the server sends into what the list shows.
 *
 * A mailbox list looks like one list and is really two, switched by a flag:
 * flat messages, or conversations with expandable members. The rules for
 * getting from one to the other are identical in every product, and every one
 * of them has a way of going wrong quietly — a miscounted unread badge, a row
 * the keyboard can land on but cannot open, a bulk action that hits more than
 * it announced.
 *
 * ## No words in here
 *
 * A message without a subject keeps its empty subject. The package does not
 * invent "(No subject)" — that is the product's word in the product's
 * language.
 *
 * ## The filter is a predicate, not a feature
 *
 * Products filter the list by their own notions ("assigned to me"). What is
 * shared is *where* the filter applies: to messages in flat mode, and to the
 * **head** of a conversation in thread mode — never to its members. Filtering
 * members instead would hide replies inside a conversation whose head passes,
 * which reads as data loss.
 */

/** A message as the list renders it. Product types may carry more. */
export interface RowMessage {
    uid: number;
    message_id?: string;
    subject: string;
    from_address: string;
    from_name: string;
    date: string;
    has_attachments: boolean;
    attachment_count: number;
    is_read: boolean;
    is_flagged: boolean;
    preview: string;
    in_reply_to?: string | null;
    references?: string | null;
    /** Only on search hits: which folder the message sits in. */
    folder?: string | null;
    /** `mailbox` = on the server, `stored` = only in the application's archive. */
    source?: 'mailbox' | 'stored';
    stored_id?: number;
}

/** A message as it appears inside a conversation. Leaner than a list row. */
export interface ThreadMessage {
    uid?: number;
    message_id?: string;
    subject?: string;
    from_address?: string;
    from_name?: string;
    date?: string;
    is_read?: boolean;
    source?: 'mailbox' | 'stored';
    /** Only with `source: 'stored'` — without it the message cannot be loaded. */
    stored_id?: number;
    direction?: string;
}

export interface Thread<M extends RowMessage = RowMessage> {
    thread_id: string;
    subject: string;
    message_count: number;
    latest_date: string | null;
    has_unread: boolean;
    messages: ThreadMessage[];
    latest?: M;
}

export interface DisplayRow<M extends RowMessage = RowMessage> {
    msg: M;
    /** The conversation this row belongs to; empty in flat mode. */
    key: string;
    count: number;
    isMember: boolean;
    /** Own stored reply — does not sit in the displayed folder. */
    isOutbound?: boolean;
    /** Unread messages of the conversation — 0 when everything is read. */
    unreadCount?: number;
    /** The newest message of the conversation (always its head row). */
    isNewest?: boolean;
}

export interface BuildDisplayRowsOptions<M extends RowMessage> {
    messages: M[];
    threads: Thread<M>[];
    groupByThread: boolean;
    /**
     * Search results are always flat, even with conversation view switched on:
     * hits come from every folder, conversations only ever from the open one.
     */
    isSearchMode: boolean;
    /** Which conversations are expanded, by `thread_id`. */
    expandedThreads: ReadonlySet<string>;
    /** Optional product filter. Applies to flat messages and conversation heads. */
    filter?: (message: M) => boolean;
    /** Builds a list row from a conversation member. */
    toRow: (message: ThreadMessage) => M;
}

/**
 * The list as it is rendered — flat messages or conversation heads with their
 * expanded members.
 */
export function buildDisplayRows<M extends RowMessage>(options: BuildDisplayRowsOptions<M>): DisplayRow<M>[] {
    const { messages, threads, groupByThread, isSearchMode, expandedThreads, filter, toRow } = options;

    if (!groupByThread || isSearchMode) {
        const base = filter ? messages.filter(filter) : messages;

        return base.map((msg) => ({ msg, key: '', count: 1, isMember: false }));
    }

    const visible = filter ? threads.filter((thread) => (thread.latest ? filter(thread.latest) : false)) : threads;

    const rows: DisplayRow<M>[] = [];

    for (const thread of visible) {
        const head = thread.latest ?? toRow(thread.messages[thread.messages.length - 1] ?? {});

        rows.push({
            msg: { ...head, subject: thread.subject, is_read: !thread.has_unread },
            key: thread.thread_id,
            count: thread.message_count,
            isMember: false,
            unreadCount: unreadCountOf(thread),
            isNewest: true,
        });

        if (thread.message_count > 1 && expandedThreads.has(thread.thread_id)) {
            // The newest message is already the head of the conversation.
            for (const member of thread.messages.slice(0, -1)) {
                rows.push({
                    msg: toRow(member),
                    key: thread.thread_id,
                    count: 0,
                    isMember: true,
                    isOutbound: member.source === 'stored',
                });
            }
        }
    }

    return rows;
}

/**
 * How many messages of a conversation are still unread.
 *
 * `has_unread` only says *that* something arrived. In a conversation of twelve
 * messages the difference between "one new" and "seven new" is exactly what
 * people order their work by.
 *
 * Falls back to 1: a server that reports `has_unread` without per-message
 * detail would otherwise read as "0 new".
 */
export function unreadCountOf(thread: Pick<Thread, 'messages' | 'has_unread'>): number {
    const counted = thread.messages.filter((message) => message.is_read === false).length;

    return counted > 0 ? counted : thread.has_unread ? 1 : 0;
}

/**
 * The rows the keyboard may move through.
 *
 * Stored replies are skipped: they cannot be opened as mailbox messages, so an
 * arrow key landing on one looks like the keyboard is stuck.
 */
export function navigableRows<M extends RowMessage>(rows: DisplayRow<M>[]): DisplayRow<M>[] {
    return rows.filter((row) => !row.isOutbound);
}

export interface BulkTargetsOptions<M extends RowMessage> {
    selectedUids: Iterable<number>;
    threads: Thread<M>[];
    groupByThread: boolean;
    isSearchMode: boolean;
}

/**
 * What a bulk action actually hits.
 *
 * The checkbox of a conversation row carries only the uid of its newest
 * message — but what was ticked is the conversation. Outside conversation
 * mode, and in search, a tick means exactly the one message.
 *
 * Stored replies and members without a uid stay out: they have nothing the
 * mailbox could act on.
 */
export function bulkTargets<M extends RowMessage>(options: BulkTargetsOptions<M>): number[] {
    const { selectedUids, threads, groupByThread, isSearchMode } = options;
    const ticked = [...selectedUids];

    if (!groupByThread || isSearchMode) {
        return ticked;
    }

    const all = new Set<number>();

    for (const uid of ticked) {
        const thread = threads.find((candidate) => candidate.messages.some((message) => message.uid === uid));

        if (!thread) {
            // Conversation no longer on the loaded page — then at least the
            // ticked message itself.
            all.add(uid);
            continue;
        }

        for (const message of thread.messages) {
            if (message.source !== 'stored' && message.uid) {
                all.add(message.uid);
            }
        }
    }

    return [...all];
}

/**
 * Every message of the open conversation that the mailbox can act on — the
 * basis for archiving hitting the whole conversation rather than one mail.
 *
 * Own stored replies stay out: they sit in the sent folder and have no uid
 * here.
 */
export function threadActionUids<M extends RowMessage>(thread: Thread<M> | null, fallbackUid: number | null | undefined): number[] {
    if (!thread) {
        return fallbackUid ? [fallbackUid] : [];
    }

    return [...new Set(thread.messages.filter((message) => message.source !== 'stored' && message.uid).map((message) => message.uid as number))];
}

/** The conversation the open message sits in, or null outside conversation mode. */
export function threadOf<M extends RowMessage>(
    threads: Thread<M>[],
    uid: number | null | undefined,
    groupByThread: boolean,
    isSearchMode: boolean,
): Thread<M> | null {
    if (!groupByThread || isSearchMode || !uid) {
        return null;
    }

    return threads.find((thread) => thread.messages.some((message) => message.uid === uid)) ?? null;
}
