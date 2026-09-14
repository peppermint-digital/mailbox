import { describe, expect, it, vi } from 'vitest';
import { act } from 'react';
import { useOpenMessage, type OpenMessage, type OpenMessageSource } from '../src/useOpenMessage';
import type { RowMessage } from '../src/rows';
import { render } from './support/render';

type Detail = { subject: string; is_read?: boolean };

function row(overrides: Partial<RowMessage> = {}): RowMessage {
    return {
        uid: 1, subject: 'B', from_address: 'a@b.de', from_name: 'A', date: '',
        has_attachments: false, attachment_count: 0, is_read: true, is_flagged: false, preview: '', ...overrides,
    };
}

function mount(source: OpenMessageSource<RowMessage, Detail>) {
    const ref: { current: OpenMessage<RowMessage, Detail> } = { current: null as never };
    function Probe() { ref.current = useOpenMessage({ source }); return null; }
    const { unmount } = render(<Probe />);
    return { ref, unmount };
}

describe('useOpenMessage', () => {
    it('opens a message and remembers where it sits', async () => {
        const source = { open: vi.fn(async () => ({ message: { subject: 'Hallo' }, folder: 'INBOX' })) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.open(row(), 'INBOX'); });

        expect(ref.current.message).toEqual({ subject: 'Hallo' });
        expect(ref.current.folder).toBe('INBOX');
        expect(ref.current.loading).toBe(false);
        unmount();
    });

    it('refuses a row without any identifier, without asking the server', async () => {
        // UID FETCH 0 is "message set is invalid", not "nothing happens".
        const source = { open: vi.fn(async () => ({ message: { subject: 'x' }, folder: null })) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.open(row({ uid: 0 }), 'INBOX'); });

        expect(source.open).not.toHaveBeenCalled();
        expect(ref.current.unopenable).toBe(true);
        expect(ref.current.loading).toBe(false);
        unmount();
    });

    it('opens an archived message that has no uid', async () => {
        const source = { open: vi.fn(async () => ({ message: { subject: 'aus dem Archiv' }, folder: 'egal' })) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.open(row({ uid: 0, stored_id: 9 }), 'INBOX'); });

        expect(source.open).toHaveBeenCalledOnce();
        // Nothing in the archive sits in a mailbox folder.
        expect(ref.current.folder).toBeNull();
        unmount();
    });

    it('believes the server about where it found the message', async () => {
        // The header index never cleans up: a hit can point at a folder the
        // message left long ago.
        const source = { open: vi.fn(async () => ({ message: { subject: 'x' }, folder: 'Archiv/2024' })) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.open(row({ folder: 'INBOX' }), 'INBOX'); });

        expect(ref.current.folder).toBe('Archiv/2024');
        unmount();
    });

    it('falls back to the folder that was asked for when the server names none', async () => {
        const source = { open: vi.fn(async () => ({ message: { subject: 'x' }, folder: null })) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.open(row(), 'Projekte/2026'); });

        expect(ref.current.folder).toBe('Projekte/2026');
        unmount();
    });

    it('keeps the two failures apart', async () => {
        const absage = { open: vi.fn(async () => ({ failure: 'Nicht gefunden' })) };
        const a = mount(absage);
        await act(async () => { await a.ref.current.open(row(), 'INBOX'); });
        expect(a.ref.current.failure).toEqual({ kind: 'server', message: 'Nicht gefunden' });
        a.unmount();

        const weg = { open: vi.fn(async () => { throw new TypeError('Failed to fetch'); }) };
        const b = mount(weg);
        await act(async () => { await b.ref.current.open(row(), 'INBOX'); });
        expect(b.ref.current.failure).toEqual({ kind: 'network' });
        b.unmount();
    });

    it('does not open something that was closed while it was on its way', async () => {
        let antworten: ((v: { message: Detail; folder: string }) => void) | null = null;
        const source = { open: vi.fn(() => new Promise<never>((res) => { antworten = res as never; })) };
        const { ref, unmount } = mount(source as never);

        await act(async () => {
            const laeuft = ref.current.open(row(), 'INBOX');
            ref.current.close();
            antworten!({ message: { subject: 'zu spaet' }, folder: 'INBOX' });
            await laeuft;
        });

        expect(ref.current.message).toBeNull();
        unmount();
    });

    it('changes fields of the open message without fetching again', async () => {
        const source = { open: vi.fn(async () => ({ message: { subject: 'x', is_read: false }, folder: 'INBOX' })) };
        const { ref, unmount } = mount(source);
        await act(async () => { await ref.current.open(row(), 'INBOX'); });

        act(() => { ref.current.patch({ is_read: true }); });

        expect(ref.current.message).toEqual({ subject: 'x', is_read: true });
        expect(source.open).toHaveBeenCalledOnce();
        unmount();
    });

    it('forgets the message when it is closed', async () => {
        const source = { open: vi.fn(async () => ({ message: { subject: 'x' }, folder: 'INBOX' })) };
        const { ref, unmount } = mount(source);
        await act(async () => { await ref.current.open(row(), 'INBOX'); });

        act(() => { ref.current.close(); });

        expect(ref.current.message).toBeNull();
        expect(ref.current.folder).toBeNull();
        unmount();
    });
});
