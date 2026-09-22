import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { useFolderActions, type FolderActionLabels } from '../src/useFolderActions';
import { render } from './support/render';

/**
 * Ordner anlegen, umbenennen, loeschen (22.09.2026).
 *
 * Geprueft wird, was still falsch sein kann: Geht beim Umbenennen wirklich der
 * ALTE Pfad mit und nicht nur der neue Name? Gewinnt die Meldung des Servers
 * oder die allgemeine? Und merkt die Seite, dass der geloeschte Ordner der
 * gerade geoeffnete war — sonst steht die Nachrichtenliste danach auf einem
 * Ordner, den es nicht mehr gibt.
 */
const texte: FolderActionLabels = {
    created: 'Ordner angelegt.',
    renamed: 'Ordner umbenannt.',
    deleted: 'Ordner gelöscht.',
    failed: 'Ordner-Aktion fehlgeschlagen.',
};

function halte<T>(optionen: Parameters<typeof useFolderActions>[0]) {
    const ref: { current: ReturnType<typeof useFolderActions> } = { current: null as never };

    function Probe() {
        ref.current = useFolderActions(optionen);

        return null;
    }

    const { unmount } = render(<Probe />);

    return { ref, unmount };
}

const antwortOk = () => Promise.resolve(new Response(JSON.stringify({ success: true }), { status: 200 }));

function grund(ueberschrieben: Partial<Parameters<typeof useFolderActions>[0]> = {}) {
    return {
        accountId: 7,
        endpoint: (id: number | string) => `/emails/${id}/folders`,
        csrfToken: 'T',
        labels: texte,
        onChanged: vi.fn(),
        fetch: vi.fn(antwortOk) as never,
        ...ueberschrieben,
    } as Parameters<typeof useFolderActions>[0];
}

describe('useFolderActions', () => {
    it('legt mit POST und nur dem Pfad an', async () => {
        const o = grund();
        const { ref, unmount } = halte(o);

        act(() => ref.current.setDialog({ open: true, mode: 'create', name: 'Kunden/2026', path: '' }));
        await act(async () => { await ref.current.submit(); });

        const [url, init] = (o.fetch as never as ReturnType<typeof vi.fn>).mock.calls[0];
        expect(url).toBe('/emails/7/folders');
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body)).toEqual({ path: 'Kunden/2026' });
        unmount();
    });

    it('schickt beim Umbenennen den ALTEN Pfad mit', async () => {
        // Ohne ihn weiss der Server nicht, WAS umbenannt werden soll — und
        // legt im schlimmsten Fall einen neuen Ordner an.
        const o = grund();
        const { ref, unmount } = halte(o);

        act(() => ref.current.setDialog({ open: true, mode: 'rename', name: 'Neu', path: 'Alt' }));
        await act(async () => { await ref.current.submit(); });

        const [, init] = (o.fetch as never as ReturnType<typeof vi.fn>).mock.calls[0];
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(init.body)).toEqual({ path: 'Alt', name: 'Neu' });
        unmount();
    });

    it('tut nichts bei einem leeren Namen', async () => {
        const o = grund();
        const { ref, unmount } = halte(o);

        act(() => ref.current.setDialog({ open: true, mode: 'create', name: '   ', path: '' }));
        await act(async () => { await ref.current.submit(); });

        expect(o.fetch).not.toHaveBeenCalled();
        unmount();
    });

    it('laesst die Meldung des Servers vorgehen', async () => {
        // Er weiss, ob der Ordner existiert, geschuetzt ist oder der Anbieter
        // den Namen ablehnt. „Ordner-Aktion fehlgeschlagen" weiss nichts davon.
        const meldungen: string[] = [];
        const o = grund({
            onMessage: (_art, text) => meldungen.push(text),
            fetch: vi.fn(() =>
                Promise.resolve(new Response(JSON.stringify({ message: 'Dieser Ordner gehört zur Grundausstattung.' }), { status: 422 })),
            ) as never,
        });
        const { ref, unmount } = halte(o);

        act(() => ref.current.setDialog({ open: true, mode: 'create', name: 'X', path: '' }));
        await act(async () => { await ref.current.submit(); });

        expect(meldungen).toEqual(['Dieser Ordner gehört zur Grundausstattung.']);
        unmount();
    });

    it('faellt auf die eigene Meldung zurueck, wenn der Server schweigt', async () => {
        const meldungen: string[] = [];
        const o = grund({
            onMessage: (_art, text) => meldungen.push(text),
            fetch: vi.fn(() => Promise.resolve(new Response('kein json', { status: 500 }))) as never,
        });
        const { ref, unmount } = halte(o);

        act(() => ref.current.setDialog({ open: true, mode: 'create', name: 'X', path: '' }));
        await act(async () => { await ref.current.submit(); });

        expect(meldungen).toEqual(['Ordner-Aktion fehlgeschlagen.']);
        unmount();
    });

    it('meldet auch einen Netzausfall, statt still nichts zu tun', async () => {
        const meldungen: string[] = [];
        const o = grund({
            onMessage: (_art, text) => meldungen.push(text),
            fetch: vi.fn(() => Promise.reject(new Error('weg'))) as never,
        });
        const { ref, unmount } = halte(o);

        act(() => ref.current.setDialog({ open: true, mode: 'create', name: 'X', path: '' }));
        await act(async () => { await ref.current.submit(); });

        expect(meldungen).toEqual(['Ordner-Aktion fehlgeschlagen.']);
        unmount();
    });

    it('sagt der Seite Bescheid, wenn der geloeschte Ordner der offene war', async () => {
        // Sonst steht die Nachrichtenliste auf einem Ordner, den es nicht mehr
        // gibt, und jeder Klick darin laeuft ins Leere.
        const weg = vi.fn();
        const o = grund({ selectedFolder: 'Alt', onDeletedCurrent: weg });
        const { ref, unmount } = halte(o);

        act(() => ref.current.setDeleteTarget({ path: 'Alt', name: 'Alt' } as never));
        await act(async () => { await ref.current.confirmDelete(); });

        expect(weg).toHaveBeenCalled();
        unmount();
    });

    it('schweigt, wenn ein anderer Ordner geloescht wurde', async () => {
        const weg = vi.fn();
        const o = grund({ selectedFolder: 'INBOX', onDeletedCurrent: weg });
        const { ref, unmount } = halte(o);

        act(() => ref.current.setDeleteTarget({ path: 'Alt', name: 'Alt' } as never));
        await act(async () => { await ref.current.confirmDelete(); });

        expect(weg).not.toHaveBeenCalled();
        unmount();
    });

    it('tut ohne Postfach gar nichts', async () => {
        const o = grund({ accountId: null });
        const { ref, unmount } = halte(o);

        act(() => ref.current.setDialog({ open: true, mode: 'create', name: 'X', path: '' }));
        await act(async () => { await ref.current.submit(); });

        expect(o.fetch).not.toHaveBeenCalled();
        unmount();
    });
});
