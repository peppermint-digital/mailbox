import { describe, expect, it } from 'vitest';
import {
    buildDisplayRows,
    bulkTargets,
    navigableRows,
    threadActionUids,
    threadOf,
    unreadCountOf,
    type RowMessage,
    type Thread,
    type ThreadMessage,
} from '../src/rows';

function message(overrides: Partial<RowMessage> = {}): RowMessage {
    return {
        uid: 1,
        subject: 'Subject',
        from_address: 'someone@example.com',
        from_name: 'Someone',
        date: '2026-09-14T10:00:00Z',
        has_attachments: false,
        attachment_count: 0,
        is_read: true,
        is_flagged: false,
        preview: '',
        ...overrides,
    };
}

function toRow(m: ThreadMessage): RowMessage {
    return message({
        uid: m.uid ?? 0,
        message_id: m.message_id,
        subject: m.subject ?? '',
        is_read: m.is_read ?? true,
        source: m.source,
        stored_id: m.stored_id,
    });
}

function thread(overrides: Partial<Thread> = {}): Thread {
    return {
        thread_id: 't1',
        subject: 'Conversation',
        message_count: 2,
        latest_date: '2026-09-14T10:00:00Z',
        has_unread: false,
        messages: [{ uid: 1, is_read: true }, { uid: 2, is_read: true }],
        ...overrides,
    };
}

const noneExpanded = new Set<string>();

describe('buildDisplayRows', () => {
    it('renders a flat list when conversations are off', () => {
        const rows = buildDisplayRows({
            messages: [message({ uid: 7 })],
            threads: [],
            groupByThread: false,
            isSearchMode: false,
            expandedThreads: noneExpanded,
            toRow,
        });

        expect(rows).toHaveLength(1);
        expect(rows[0]).toMatchObject({ key: '', count: 1, isMember: false });
    });

    it('stays flat in search mode even with conversations on', () => {
        // Hits come from every folder; conversations only ever from the open one.
        const rows = buildDisplayRows({
            messages: [message({ uid: 7 }), message({ uid: 8 })],
            threads: [thread()],
            groupByThread: true,
            isSearchMode: true,
            expandedThreads: new Set(['t1']),
            toRow,
        });

        expect(rows).toHaveLength(2);
        expect(rows.every((row) => row.key === '')).toBe(true);
    });

    it('shows one head row per conversation while collapsed', () => {
        const rows = buildDisplayRows({
            messages: [],
            threads: [thread({ message_count: 5 })],
            groupByThread: true,
            isSearchMode: false,
            expandedThreads: noneExpanded,
            toRow,
        });

        expect(rows).toHaveLength(1);
        expect(rows[0]).toMatchObject({ key: 't1', count: 5, isNewest: true, isMember: false });
    });

    it('adds members when expanded, without repeating the newest', () => {
        const rows = buildDisplayRows({
            messages: [],
            threads: [
                thread({
                    message_count: 3,
                    messages: [{ uid: 1 }, { uid: 2 }, { uid: 3 }],
                }),
            ],
            groupByThread: true,
            isSearchMode: false,
            expandedThreads: new Set(['t1']),
            toRow,
        });

        // Head plus the two older members — the newest is already the head.
        expect(rows).toHaveLength(3);
        expect(rows.slice(1).every((row) => row.isMember)).toBe(true);
        expect(rows.slice(1).map((row) => row.msg.uid)).toEqual([1, 2]);
    });

    it('marks stored members as outbound', () => {
        const rows = buildDisplayRows({
            messages: [],
            threads: [
                thread({
                    message_count: 2,
                    messages: [{ uid: 0, source: 'stored', stored_id: 9 }, { uid: 2 }],
                }),
            ],
            groupByThread: true,
            isSearchMode: false,
            expandedThreads: new Set(['t1']),
            toRow,
        });

        expect(rows[1].isOutbound).toBe(true);
    });

    it('takes the conversation subject for the head row', () => {
        const rows = buildDisplayRows({
            messages: [],
            threads: [thread({ subject: 'The whole conversation', latest: message({ subject: 'Re: something' }) })],
            groupByThread: true,
            isSearchMode: false,
            expandedThreads: noneExpanded,
            toRow,
        });

        expect(rows[0].msg.subject).toBe('The whole conversation');
    });

    it('invents no subject for a message that has none', () => {
        // "(No subject)" is the product's word, in the product's language.
        const rows = buildDisplayRows({
            messages: [],
            threads: [
                thread({
                    subject: '',
                    message_count: 2,
                    messages: [{ uid: 1 }, { uid: 2 }],
                }),
            ],
            groupByThread: true,
            isSearchMode: false,
            expandedThreads: noneExpanded,
            toRow,
        });

        expect(rows[0].msg.subject).toBe('');
    });

    it('applies the product filter to conversation heads, not to members', () => {
        // Filtering members would hide replies inside a passing conversation.
        const rows = buildDisplayRows({
            messages: [],
            threads: [
                thread({
                    message_count: 3,
                    messages: [{ uid: 1 }, { uid: 2 }, { uid: 3 }],
                    latest: message({ uid: 3, from_name: 'Keep' }),
                }),
            ],
            groupByThread: true,
            isSearchMode: false,
            expandedThreads: new Set(['t1']),
            filter: (m) => m.from_name === 'Keep',
            toRow,
        });

        expect(rows).toHaveLength(3);
    });

    it('drops a conversation whose head fails the filter', () => {
        const rows = buildDisplayRows({
            messages: [],
            threads: [thread({ latest: message({ from_name: 'Other' }) })],
            groupByThread: true,
            isSearchMode: false,
            expandedThreads: noneExpanded,
            filter: (m) => m.from_name === 'Keep',
            toRow,
        });

        expect(rows).toHaveLength(0);
    });

    it('drops a conversation without a head when a filter is set', () => {
        // No head means nothing to judge — and an unjudged row must not slip through.
        const rows = buildDisplayRows({
            messages: [],
            threads: [thread({ latest: undefined })],
            groupByThread: true,
            isSearchMode: false,
            expandedThreads: noneExpanded,
            filter: () => true,
            toRow,
        });

        expect(rows).toHaveLength(0);
    });
});

describe('unreadCountOf', () => {
    it('counts the unread messages of a conversation', () => {
        expect(
            unreadCountOf({
                has_unread: true,
                messages: [{ is_read: false }, { is_read: false }, { is_read: true }],
            }),
        ).toBe(2);
    });

    it('falls back to 1 when the server reports unread without detail', () => {
        // Otherwise a conversation that has something new would read as "0 new".
        expect(unreadCountOf({ has_unread: true, messages: [{}, {}] })).toBe(1);
    });

    it('is 0 when everything is read', () => {
        expect(unreadCountOf({ has_unread: false, messages: [{ is_read: true }] })).toBe(0);
    });
});

describe('navigableRows', () => {
    it('skips stored replies so the keyboard never lands on an unopenable row', () => {
        const rows = [
            { msg: message({ uid: 1 }), key: '', count: 1, isMember: false },
            { msg: message({ uid: 0 }), key: 't1', count: 0, isMember: true, isOutbound: true },
            { msg: message({ uid: 3 }), key: '', count: 1, isMember: false },
        ];

        expect(navigableRows(rows).map((row) => row.msg.uid)).toEqual([1, 3]);
    });
});

describe('bulkTargets', () => {
    it('means exactly the ticked message outside conversation mode', () => {
        expect(
            bulkTargets({ selectedUids: [1, 2], threads: [thread()], groupByThread: false, isSearchMode: false }),
        ).toEqual([1, 2]);
    });

    it('means exactly the ticked message in search mode', () => {
        expect(
            bulkTargets({ selectedUids: [1], threads: [thread()], groupByThread: true, isSearchMode: true }),
        ).toEqual([1]);
    });

    it('expands a tick to the whole conversation', () => {
        const targets = bulkTargets({
            selectedUids: [1],
            threads: [thread({ messages: [{ uid: 1 }, { uid: 2 }, { uid: 3 }] })],
            groupByThread: true,
            isSearchMode: false,
        });

        expect(targets.sort()).toEqual([1, 2, 3]);
    });

    it('leaves stored replies and uid-less members out', () => {
        const targets = bulkTargets({
            selectedUids: [1],
            threads: [
                thread({
                    messages: [{ uid: 1 }, { uid: 2, source: 'stored' }, { uid: undefined }],
                }),
            ],
            groupByThread: true,
            isSearchMode: false,
        });

        expect(targets).toEqual([1]);
    });

    it('falls back to the ticked message when its conversation left the page', () => {
        expect(
            bulkTargets({ selectedUids: [99], threads: [thread()], groupByThread: true, isSearchMode: false }),
        ).toEqual([99]);
    });

    it('counts a message shared by two ticks only once', () => {
        const targets = bulkTargets({
            selectedUids: [1, 2],
            threads: [thread({ messages: [{ uid: 1 }, { uid: 2 }] })],
            groupByThread: true,
            isSearchMode: false,
        });

        expect(targets.sort()).toEqual([1, 2]);
    });
});

describe('threadActionUids', () => {
    it('archives the whole conversation, stored replies excluded', () => {
        const uids = threadActionUids(thread({ messages: [{ uid: 1 }, { uid: 2, source: 'stored' }, { uid: 3 }] }), 1);

        expect(uids.sort()).toEqual([1, 3]);
    });

    it('falls back to the open message outside conversation mode', () => {
        expect(threadActionUids(null, 42)).toEqual([42]);
    });

    it('acts on nothing when there is no uid to act on', () => {
        expect(threadActionUids(null, null)).toEqual([]);
    });
});

describe('threadOf', () => {
    it('finds the conversation the open message sits in', () => {
        expect(threadOf([thread()], 2, true, false)?.thread_id).toBe('t1');
    });

    it('has no conversation in search mode', () => {
        expect(threadOf([thread()], 2, true, true)).toBeNull();
    });

    it('has no conversation when conversations are off', () => {
        expect(threadOf([thread()], 2, false, false)).toBeNull();
    });
});
