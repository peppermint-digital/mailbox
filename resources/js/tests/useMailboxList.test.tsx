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

describe('rows that did not come from the source', () => {
    it('shows search results flat, whatever mode was on', async () => {
        // Hits come from every folder; conversations only ever from one.
        const source = { list: vi.fn(async () => ({ messages: [], threads: [thread('t1', msg(9))], total: 1 })) };
        const { ref, unmount } = mount(source, true);

        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX', grouped: true }); });
        expect(ref.current.threads).toHaveLength(1);

        act(() => { ref.current.showRows([msg(5), msg(6)], 2); });

        expect(ref.current.messages).toHaveLength(2);
        expect(ref.current.threads).toHaveLength(0);
        expect(ref.current.total).toBe(2);
        unmount();
    });

    it('is not overwritten by a load that was still in flight', async () => {
        let antworten: ((p: { messages: RowMessage[]; threads: never[]; total: number }) => void) | null = null;
        const source = { list: vi.fn(() => new Promise<never>((res) => { antworten = res as never; })) };
        const { ref, unmount } = mount(source as never);

        await act(async () => {
            const laeuft = ref.current.load({ accountId: 1, folder: 'INBOX' });
            ref.current.showRows([msg(5)], 1);
            antworten!({ messages: [msg(1)], threads: [], total: 9 });
            await laeuft;
        });

        expect(ref.current.messages.map((m) => m.uid)).toEqual([5]);
        unmount();
    });

    it('empties both lists when the account changes', async () => {
        const source = { list: vi.fn(async () => seite([msg(1)], 7)) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });
        act(() => { ref.current.clear(); });

        expect(ref.current.messages).toHaveLength(0);
        expect(ref.current.threads).toHaveLength(0);
        expect(ref.current.total).toBe(0);
        unmount();
    });
});

describe('patching a row inside a conversation', () => {
    function kette(): Thread<RowMessage> {
        return {
            thread_id: 't1', subject: 'K', message_count: 2, latest_date: null, has_unread: true,
            messages: [msg(1, { is_read: false }), msg(2, { is_read: false })],
            latest: msg(2, { is_read: false }),
        };
    }

    const quelle = () => ({ list: vi.fn(async () => ({ messages: [], threads: [kette()], total: 1 })) });

    it('reaches a member, not just the head', async () => {
        const { ref, unmount } = mount(quelle(), true);
        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX', grouped: true }); });

        act(() => { ref.current.patchRow(1, { is_read: true } as never); });

        expect(ref.current.threads[0].messages[0].is_read).toBe(true);
        unmount();
    });

    it('recomputes the unread dot from what the members now say', async () => {
        // The dot means "something in here is unread" — it has to follow.
        const { ref, unmount } = mount(quelle(), true);
        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX', grouped: true }); });

        act(() => { ref.current.patchRow(1, { is_read: true } as never); });
        expect(ref.current.threads[0].has_unread).toBe(true);

        act(() => { ref.current.patchRow(2, { is_read: true } as never); });
        expect(ref.current.threads[0].has_unread).toBe(false);
        unmount();
    });

    it('leaves conversations the uid does not belong to alone', async () => {
        const { ref, unmount } = mount(quelle(), true);
        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX', grouped: true }); });
        const vorher = ref.current.threads[0];

        act(() => { ref.current.patchRow(999, { is_read: true } as never); });

        expect(ref.current.threads[0]).toBe(vorher);
        unmount();
    });

    it('patches by message id, because a conversation row is a copy', async () => {
        const mitId = { ...kette(), latest: msg(2, { message_id: '<x@y>' }) };
        const src = { list: vi.fn(async () => ({ messages: [], threads: [mitId], total: 1 })) };
        const { ref, unmount } = mount(src, true);
        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX', grouped: true }); });

        act(() => { ref.current.patchRowById('<x@y>', { subject: 'zugewiesen' } as never); });

        expect(ref.current.threads[0].latest?.subject).toBe('zugewiesen');
        unmount();
    });
});

describe('a message that left the mailbox', () => {
    const flach = () => ({ list: vi.fn(async () => seite([msg(1), msg(2), msg(3)], 9)) });

    it('is taken out of the flat list without asking the server again', async () => {
        const source = flach();
        const { ref, unmount } = mount(source);
        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });

        await act(async () => { await ref.current.messageLeft(2); });

        expect(ref.current.messages.map((m) => m.uid)).toEqual([1, 3]);
        expect(ref.current.total).toBe(8);
        expect(source.list).toHaveBeenCalledOnce();
        unmount();
    });

    it('makes the conversation list come back from the server', async () => {
        // A conversation is a bundle; removing one message by uid would leave a
        // row that lies about what it holds. The Vue version did exactly that.
        const source = { list: vi.fn(async () => ({ messages: [], threads: [thread('t1', msg(1))], total: 1 })) };
        const { ref, unmount } = mount(source, true);
        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX', grouped: true }); });

        await act(async () => { await ref.current.messageLeft(1); });

        expect(source.list).toHaveBeenCalledTimes(2);
        expect(source.list.mock.calls[1][0]).toMatchObject({ folder: 'INBOX', grouped: true, refresh: true });
        unmount();
    });

    it('reloads what was last asked for, without being told again', async () => {
        const source = { list: vi.fn(async () => seite([msg(1)])) };
        const { ref, unmount } = mount(source);
        await act(async () => { await ref.current.load({ accountId: 7, folder: 'Archiv', page: 3 }); });

        await act(async () => { await ref.current.reload(); });

        expect(source.list.mock.calls[1][0]).toMatchObject({ accountId: 7, folder: 'Archiv', page: 3 });
        unmount();
    });

    it('reloads nothing when nothing was ever loaded', async () => {
        const source = { list: vi.fn(async () => seite([])) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.reload(); });

        expect(source.list).not.toHaveBeenCalled();
        unmount();
    });
});

describe('several messages at once', () => {
    it('takes them all out of the flat list in one go', async () => {
        const source = { list: vi.fn(async () => seite([msg(1), msg(2), msg(3), msg(4)], 20)) };
        const { ref, unmount } = mount(source);
        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });

        await act(async () => { await ref.current.messagesLeft([2, 4]); });

        expect(ref.current.messages.map((m) => m.uid)).toEqual([1, 3]);
        expect(ref.current.total).toBe(18);
        expect(source.list).toHaveBeenCalledOnce();
        unmount();
    });

    it('reloads once for a whole bulk, not once per message', async () => {
        const source = { list: vi.fn(async () => ({ messages: [], threads: [thread('t1', msg(1))], total: 1 })) };
        const { ref, unmount } = mount(source, true);
        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX', grouped: true }); });

        await act(async () => { await ref.current.messagesLeft([1, 2, 3]); });

        expect(source.list).toHaveBeenCalledTimes(2);
        unmount();
    });

    it('rechnet die Seitenzahl aus der Seitengroesse des Endpunkts', async () => {
        // Ohne `per_page` aus der Antwort muesste jedes Produkt eine Kopie der
        // serverseitigen Seitengroesse fuehren. Laufen die auseinander, zeigt
        // die Blaetterung „3 von 7", waehrend es 5 Seiten gibt — und nichts
        // faellt dabei aus.
        const source = { list: vi.fn(async () => ({ messages: [msg(1)], threads: [], total: 130, perPage: 50 })) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });

        expect(ref.current.perPage).toBe(50);
        expect(ref.current.totalPages).toBe(3);
        unmount();
    });

    it('bleibt bei 25 pro Seite, solange der Endpunkt nichts sagt', async () => {
        const source = { list: vi.fn(async () => seite([msg(1)], 60)) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });

        expect(ref.current.perPage).toBe(25);
        expect(ref.current.totalPages).toBe(3);
        unmount();
    });

    it('changes several rows at once', async () => {
        const source = { list: vi.fn(async () => seite([msg(1, { is_read: false }), msg(2, { is_read: false }), msg(3, { is_read: false })])) };
        const { ref, unmount } = mount(source);
        await act(async () => { await ref.current.load({ accountId: 1, folder: 'INBOX' }); });

        act(() => { ref.current.patchRows([1, 3], { is_read: true } as never); });

        expect(ref.current.messages.map((m) => m.is_read)).toEqual([true, false, true]);
        unmount();
    });
});
