import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { DisplayRow, RowMessage } from '../src/rows';
import { useAutoOpen } from '../src/useAutoOpen';
import { render } from './support/render';

/**
 * Beim Betreten eines Ordners von selbst eine Nachricht oeffnen (22.09.2026).
 *
 * Die eine Regel, die hier wirklich zaehlt: Es wird NUR eine bereits gelesene
 * Nachricht geoeffnet. Oeffnen zaehlt als Lesen — wer beim Aufmachen des
 * Postfachs automatisch die neueste, ungelesene Nachricht geoeffnet bekommt,
 * hat sie damit als gelesen markiert, ohne sie gesehen zu haben. In einem
 * Gruppenpostfach sieht die Kollegin sie danach nicht mehr als neu.
 */
function msg(uid: number, rest: Partial<RowMessage> = {}): RowMessage {
    return {
        uid,
        subject: `Betreff ${uid}`,
        from_address: 'a@b.test',
        from_name: 'A',
        date: null,
        has_attachments: false,
        attachment_count: 0,
        is_read: true,
        is_flagged: false,
        preview: '',
        ...rest,
    } as RowMessage;
}

const zeile = (m: RowMessage): DisplayRow<RowMessage> => ({ msg: m, key: `k${m.uid}`, count: 1, isMember: false });

function haenge(optionen: Parameters<typeof useAutoOpen<RowMessage>>[0]) {
    function Probe(p: Parameters<typeof useAutoOpen<RowMessage>>[0]) {
        useAutoOpen(p);

        return null;
    }

    const { rerender, unmount } = render(<Probe {...optionen} />);

    return { rerender: (n: Parameters<typeof useAutoOpen<RowMessage>>[0]) => rerender(<Probe {...n} />), unmount };
}

describe('useAutoOpen', () => {
    it('oeffnet die neueste GELESENE Nachricht', () => {
        const geoeffnet = vi.fn();
        const { unmount } = haenge({
            rows: [zeile(msg(3, { is_read: false })), zeile(msg(2)), zeile(msg(1))],
            key: 'k1:INBOX',
            onOpen: geoeffnet,
        });

        expect(geoeffnet).toHaveBeenCalledOnce();
        expect(geoeffnet.mock.calls[0][0].uid).toBe(2);
        unmount();
    });

    it('laesst ungelesene Nachrichten in Ruhe', () => {
        // Der Kern. Gaebe es hier eine Ausnahme, waere der Ungelesen-Stand
        // eines Gruppenpostfachs nach jedem Oeffnen ein anderer.
        const geoeffnet = vi.fn();
        const { unmount } = haenge({
            rows: [zeile(msg(3, { is_read: false })), zeile(msg(2, { is_read: false }))],
            key: 'k1:INBOX',
            onOpen: geoeffnet,
        });

        expect(geoeffnet).not.toHaveBeenCalled();
        unmount();
    });

    it('haelt sich heraus, wenn schon etwas offen ist', () => {
        const geoeffnet = vi.fn();
        const { unmount } = haenge({
            rows: [zeile(msg(2))],
            key: 'k1:INBOX',
            openedUid: 2,
            onOpen: geoeffnet,
        });

        expect(geoeffnet).not.toHaveBeenCalled();
        unmount();
    });

    it('oeffnet nicht wieder, nachdem jemand geschlossen hat', () => {
        // Ohne dieses Gedaechtnis liesse sich die Ansicht gar nicht leeren:
        // Jedes Schliessen wuerde sofort ein neues Oeffnen ausloesen.
        const geoeffnet = vi.fn();
        const zeilen = [zeile(msg(2))];
        const { rerender, unmount } = haenge({ rows: zeilen, key: 'k1:INBOX', onOpen: geoeffnet });

        expect(geoeffnet).toHaveBeenCalledOnce();

        act(() => rerender({ rows: zeilen, key: 'k1:INBOX', onOpen: geoeffnet }));

        expect(geoeffnet).toHaveBeenCalledOnce();
        unmount();
    });

    it('oeffnet im naechsten Ordner wieder', () => {
        const geoeffnet = vi.fn();
        const { rerender, unmount } = haenge({ rows: [zeile(msg(2))], key: 'k1:INBOX', onOpen: geoeffnet });

        act(() => rerender({ rows: [zeile(msg(9))], key: 'k1:Archiv', onOpen: geoeffnet }));

        expect(geoeffnet).toHaveBeenCalledTimes(2);
        expect(geoeffnet.mock.calls[1][0].uid).toBe(9);
        unmount();
    });

    it('wartet, solange es noch nichts zu oeffnen gibt', () => {
        const geoeffnet = vi.fn();
        const { rerender, unmount } = haenge({ rows: [], key: 'k1:INBOX', onOpen: geoeffnet });

        expect(geoeffnet).not.toHaveBeenCalled();

        act(() => rerender({ rows: [zeile(msg(2))], key: 'k1:INBOX', onOpen: geoeffnet }));

        expect(geoeffnet).toHaveBeenCalledOnce();
        unmount();
    });

    it('schweigt, solange das Produkt es abgeschaltet hat', () => {
        const geoeffnet = vi.fn();
        const { unmount } = haenge({ rows: [zeile(msg(2))], key: 'k1:INBOX', onOpen: geoeffnet, enabled: false });

        expect(geoeffnet).not.toHaveBeenCalled();
        unmount();
    });
});
