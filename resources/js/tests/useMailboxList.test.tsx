import { describe, expect, it, vi } from 'vitest';
import { act } from 'react';
import { useMailboxList, type MailboxList, type MailboxListSource, type ListParams } from '../src/useMailboxList';
import type { RowMessage, Thread } from '../src/rows';
import { render } from './support/render';

function msg(uid: number, extra: Partial<RowMessage> = {}): RowMessage {
    return {
        uid, subject: `Betreff ${uid}`, from_address: 'a@b.de', from_name: 'A', date: '',
        has_attachments: false, attachment_count: 0, is_read: true, is_flagged: false, preview: '', ...extra,
    };
}

function thread(id: string, latest: RowMessage): Thread<RowMessage> {
    return { thread_id: id, subject: 'K', message_count: 2, latest_date: null, has_unread: false, messages: [], latest };
}

/** Rendert den Haken und gibt eine Referenz auf seinen jeweils aktuellen Stand. */
function mount(source: MailboxListSource<RowMessage>, initialGrouped = false) {
    const ref: { current: MailboxList<RowMessage> } = { current: null as never };

    function Probe() {
        ref.current = useMailboxList({ source, initialGrouped });
        return null;
    }

    const { unmount } = render(<Probe />);

    return { ref, unmount };
}

const seite = (messages: RowMessage[], total = messages.length) => ({ messages, threads: [], total });

describe('useMailboxList', () => {
    it('loads a flat page and remembers the total', async () => {
        const source = { list: vi.fn(async () => seite([msg(1), msg(2)], 42)) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX', page: 1 }); });

        expect(ref.current.messages).toHaveLength(2);
        expect(ref.current.total).toBe(42);
        expect(ref.current.loading).toBe(false);
        unmount();
    });

    it('does nothing without an account or a folder', async () => {
        const source = { list: vi.fn(async () => seite([])) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.load({ folder: 'INBOX' }); });
        await act(async () => { await ref.current.load({ accountId: 1 }); });

        expect(source.list).not.toHaveBeenCalled();
        unmount();
    });

    it('holds rows in only one of the two lists', async () => {
        // Leftovers of the other mode would show through while this one loads.
        const source = {
            list: vi.fn(async (p: ListParams) =>
                p.grouped ? { messages: [], threads: [thread('t1', msg(9))], total: 1 } : seite([msg(1)]),
            ),
        };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });
        expect(ref.current.messages).toHaveLength(1);
        expect(ref.current.threads).toHaveLength(0);

        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX', grouped: true }); });
        expect(ref.current.threads).toHaveLength(1);
        expect(ref.current.messages).toHaveLength(0);
        unmount();
    });

    it('starts at page 1 when the mode changes', async () => {
        // Page 3 of conversations is not page 3 of messages.
        const source = { list: vi.fn(async () => seite([msg(1)])) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX', page: 3 }); });
        expect(ref.current.page).toBe(3);

        await act(async () => { await ref.current.setGrouped(true, 1, 'INBOX'); });
        expect(ref.current.page).toBe(1);
        expect(ref.current.grouped).toBe(true);
        unmount();
    });

    describe('the two failures stay apart', () => {
        it('reports a refusal with the reason the server gave', async () => {
            const source = { list: vi.fn(async () => ({ failure: 'Postfach gesperrt' })) };
            const { ref, unmount } = mount(source);

            await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });

            expect(ref.current.failure).toEqual({ kind: 'server', message: 'Postfach gesperrt' });
            unmount();
        });

        it('reports a request that never arrived as its own kind', async () => {
            const source = { list: vi.fn(async () => { throw new TypeError('Failed to fetch'); }) };
            const { ref, unmount } = mount(source);

            await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });

            expect(ref.current.failure).toEqual({ kind: 'network' });
            unmount();
        });

        it('stops the spinner either way', async () => {
            const source = { list: vi.fn(async () => { throw new Error('weg'); }) };
            const { ref, unmount } = mount(source);

            await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });

            expect(ref.current.loading).toBe(false);
            expect(ref.current.refreshing).toBe(false);
            unmount();
        });

        it('keeps the rows that were already shown', async () => {
            // Emptying the list on a failed reload looks like an empty mailbox.
            let scheitern = false;
            const source = { list: vi.fn(async () => { if (scheitern) throw new Error('weg'); return seite([msg(1)]); }) };
            const { ref, unmount } = mount(source);

            await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });
            scheitern = true;
            await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });

            expect(ref.current.messages).toHaveLength(1);
            expect(ref.current.failure?.kind).toBe('network');
            unmount();
        });
    });

    it('lets a slower earlier load not overwrite the newer one', async () => {
        // Clicking two folders quickly must show the second, not whichever
        // answered last.
        const antworten: Array<(p: { messages: RowMessage[]; threads: never[]; total: number }) => void> = [];
        const source = {
            list: vi.fn(() => new Promise<{ messages: RowMessage[]; threads: never[]; total: number }>((res) => { antworten.push(res); })),
        };
        const { ref, unmount } = mount(source as never);

        let ersterFertig: Promise<void>;
        let zweiterFertig: Promise<void>;

        await act(async () => {
            ersterFertig = ref.current.load({ accountId: 1, folder: 'A' });
            zweiterFertig = ref.current.load({ accountId: 1, folder: 'B' });
            // Der ZWEITE antwortet zuerst, der erste kommt verspaetet nach.
            antworten[1]({ messages: [msg(2)], threads: [], total: 1 });
            antworten[0]({ messages: [msg(1)], threads: [], total: 1 });
            await Promise.all([ersterFertig, zweiterFertig]);
        });

        expect(ref.current.messages.map((m) => m.uid)).toEqual([2]);
        unmount();
    });

    it('changes one row without reloading', async () => {
        const source = { list: vi.fn(async () => seite([msg(1, { is_read: false }), msg(2)])) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });
        act(() => { ref.current.patchRow(1, { is_read: true }); });

        expect(ref.current.messages.find((m) => m.uid === 1)?.is_read).toBe(true);
        expect(source.list).toHaveBeenCalledOnce();
        unmount();
    });

    it('drops rows and corrects the total', async () => {
        const source = { list: vi.fn(async () => seite([msg(1), msg(2), msg(3)], 10)) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });
        act(() => { ref.current.removeRows([1, 3]); });

        expect(ref.current.messages.map((m) => m.uid)).toEqual([2]);
        expect(ref.current.total).toBe(8);
        unmount();
    });

    it('never counts the total below zero', async () => {
        const source = { list: vi.fn(async () => seite([msg(1), msg(2)], 1)) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });
        act(() => { ref.current.removeRows([1, 2]); });

        expect(ref.current.total).toBe(0);
        unmount();
    });
});
