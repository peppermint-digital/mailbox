import { describe, expect, it, vi } from 'vitest';
import { act } from 'react';
import { preferredFolder, useMailboxFolders, type FolderSource, type MailboxFolders } from '../src/useMailboxFolders';
import type { MailFolder } from '../src/MailFolderList';
import { render } from './support/render';

function mount(source: FolderSource) {
    const ref: { current: MailboxFolders } = { current: null as never };
    function Probe() {
        ref.current = useMailboxFolders({ source });
        return null;
    }
    const { unmount } = render(<Probe />);
    return { ref, unmount };
}

const ordner = (...pfade: string[]): MailFolder[] => pfade.map((p) => ({ name: p, path: p }));

describe('preferredFolder', () => {
    it('opens INBOX when there is one', () => {
        expect(preferredFolder(ordner('Archiv', 'INBOX', 'Notizen'))).toBe('INBOX');
    });

    it('recognises INBOX whatever its case', () => {
        expect(preferredFolder(ordner('Archiv', 'inbox'))).toBe('inbox');
    });

    it('falls back to the first only when there is no INBOX', () => {
        // Opening into "Archiv" because A sorts first is an accident, not a default.
        expect(preferredFolder(ordner('Archiv', 'Notizen'))).toBe('Archiv');
    });

    it('has nothing to open in an empty mailbox', () => {
        expect(preferredFolder([])).toBeNull();
    });
});

describe('useMailboxFolders', () => {
    it('loads the sidebar and names the folder to open', async () => {
        const source = { list: vi.fn(async () => ordner('Archiv', 'INBOX')) };
        const { ref, unmount } = mount(source);

        let gewaehlt: string | null = null;
        await act(async () => { gewaehlt = await ref.current.load(1); });

        expect(ref.current.folders).toHaveLength(2);
        expect(gewaehlt).toBe('INBOX');
        expect(ref.current.loading).toBe(false);
        unmount();
    });

    it('opens nothing without an account', async () => {
        const source = { list: vi.fn(async () => ordner('INBOX')) };
        const { ref, unmount } = mount(source);

        await act(async () => { await ref.current.load(''); });

        expect(source.list).not.toHaveBeenCalled();
        unmount();
    });

    it('keeps the two failures apart, like the list does', async () => {
        const absage = { list: vi.fn(async () => ({ failure: 'Kein Zugriff' })) };
        const a = mount(absage);
        await act(async () => { await a.ref.current.load(1); });
        expect(a.ref.current.failure).toEqual({ kind: 'server', message: 'Kein Zugriff' });
        a.unmount();

        const weg = { list: vi.fn(async () => { throw new TypeError('Failed to fetch'); }) };
        const b = mount(weg);
        await act(async () => { await b.ref.current.load(1); });
        expect(b.ref.current.failure).toEqual({ kind: 'network' });
        b.unmount();
    });

    it('names no folder to open when the load failed', async () => {
        const source = { list: vi.fn(async () => ({ failure: undefined })) };
        const { ref, unmount } = mount(source);

        let gewaehlt: string | null = 'x';
        await act(async () => { gewaehlt = await ref.current.load(1); });

        expect(gewaehlt).toBeNull();
        unmount();
    });

    describe('the quiet refresh', () => {
        it('updates the list', async () => {
            const source = { list: vi.fn(async () => ordner('INBOX', 'Neu')) };
            const { ref, unmount } = mount(source);

            await act(async () => { await ref.current.refreshQuietly(1); });

            expect(ref.current.folders).toHaveLength(2);
            unmount();
        });

        it('reports nothing when it fails — the action already spoke', async () => {
            let scheitern = false;
            const source = { list: vi.fn(async () => { if (scheitern) throw new Error('weg'); return ordner('INBOX'); }) };
            const { ref, unmount } = mount(source);

            await act(async () => { await ref.current.load(1); });
            scheitern = true;
            await act(async () => { await ref.current.refreshQuietly(1); });

            expect(ref.current.failure).toBeNull();
            expect(ref.current.folders).toHaveLength(1);
            unmount();
        });
    });

    describe('the move targets', () => {
        it('are asked for once and kept', async () => {
            const listTargets = vi.fn(async () => ordner('A', 'B'));
            const { ref, unmount } = mount({ list: vi.fn(async () => ordner('INBOX')), listTargets });

            await act(async () => { await ref.current.loadTargetsOnce(1); });
            await act(async () => { await ref.current.loadTargetsOnce(1); });

            expect(listTargets).toHaveBeenCalledOnce();
            expect(ref.current.targets).toHaveLength(2);
            unmount();
        });

        it('leave the menu empty rather than blocking it when they fail', async () => {
            const { ref, unmount } = mount({
                list: vi.fn(async () => ordner('INBOX')),
                listTargets: vi.fn(async () => { throw new Error('weg'); }),
            });

            await act(async () => { await ref.current.loadTargetsOnce(1); });

            expect(ref.current.targets).toEqual([]);
            expect(ref.current.failure).toBeNull();
            unmount();
        });
    });
});
