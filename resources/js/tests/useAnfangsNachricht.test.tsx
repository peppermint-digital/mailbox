import { describe, expect, it, vi } from 'vitest';
import type { DisplayRow, RowMessage } from '../src/rows';
import { useAnfangsNachricht } from '../src/useAnfangsNachricht';
import { render } from './support/render';

/**
 * Die Nachricht oeffnen, auf die ein Verweis von aussen zeigt (#9832).
 *
 * Der Unterschied zu `useAutoOpen` ist die Herkunft der Entscheidung: Dort
 * sucht der Haken selbst etwas aus, hier hat jemand geklickt. Daraus folgen
 * die zwei Regeln, die dieser Test festhaelt — es wird auch UNGELESENES
 * geoeffnet, und wenn die verlangte Nachricht nicht da ist, wird NICHTS
 * geoeffnet statt irgendetwas.
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

function haenge(optionen: Parameters<typeof useAnfangsNachricht<RowMessage>>[0]) {
    function Probe(p: Parameters<typeof useAnfangsNachricht<RowMessage>>[0]) {
        useAnfangsNachricht(p);

        return null;
    }

    const { rerender, unmount } = render(<Probe {...optionen} />);

    return {
        rerender: (n: Parameters<typeof useAnfangsNachricht<RowMessage>>[0]) => rerender(<Probe {...n} />),
        unmount,
    };
}

describe('useAnfangsNachricht', () => {
    it('oeffnet genau die verlangte Nachricht', () => {
        const geoeffnet = vi.fn();
        const { unmount } = haenge({
            rows: [zeile(msg(3)), zeile(msg(2)), zeile(msg(1))],
            key: 'k:INBOX',
            uid: 2,
            onOpen: geoeffnet,
        });

        expect(geoeffnet).toHaveBeenCalledTimes(1);
        expect(geoeffnet.mock.calls[0][0].uid).toBe(2);
        unmount();
    });

    /**
     * Die UID kommt aus einer Adresse als Zeichenkette und aus der Liste als
     * Zahl. `'2' === 2` ist falsch — dieselbe Falle wie bei `anfangsKonto`.
     */
    it('trifft auch, wenn die UID als Zeichenkette hereinkommt', () => {
        const geoeffnet = vi.fn();
        const { unmount } = haenge({
            rows: [zeile(msg(2))],
            key: 'k:INBOX',
            uid: '2',
            onOpen: geoeffnet,
        });

        expect(geoeffnet).toHaveBeenCalledTimes(1);
        unmount();
    });

    /**
     * `useAutoOpen` oeffnet bewusst nur Gelesenes, weil es seine eigene Wahl
     * ist. Hier hat ein Mensch auf genau diese Mail geklickt.
     */
    it('oeffnet auch eine ungelesene Nachricht', () => {
        const geoeffnet = vi.fn();
        const { unmount } = haenge({
            rows: [zeile(msg(5, { is_read: false }))],
            key: 'k:INBOX',
            uid: 5,
            onOpen: geoeffnet,
        });

        expect(geoeffnet).toHaveBeenCalledTimes(1);
        unmount();
    });

    /**
     * Der gefaehrlichere Fehler waere, IRGENDEINE Nachricht zu oeffnen: Das
     * saehe wie ein Treffer aus. Eine UID gilt nur, solange die Mail in
     * ihrem Ordner liegt.
     */
    it('oeffnet nichts, wenn die verlangte Nachricht nicht da ist — und sagt es', () => {
        const geoeffnet = vi.fn();
        const fehlt = vi.fn();
        const { unmount } = haenge({
            rows: [zeile(msg(1)), zeile(msg(2))],
            key: 'k:INBOX',
            uid: 999,
            onOpen: geoeffnet,
            onFehlt: fehlt,
        });

        expect(geoeffnet).not.toHaveBeenCalled();
        expect(fehlt).toHaveBeenCalledTimes(1);
        unmount();
    });

    /**
     * Eine leere Liste ist noch keine Antwort — sie kann heissen, dass die
     * Zeilen noch nicht da sind. Wer hier „fehlt" meldet, meldet es jedes
     * Mal zu frueh.
     */
    it('meldet nichts, solange die Liste leer ist', () => {
        const fehlt = vi.fn();
        const geoeffnet = vi.fn();
        const { rerender, unmount } = haenge({
            rows: [],
            key: 'k:INBOX',
            uid: 7,
            onOpen: geoeffnet,
            onFehlt: fehlt,
        });

        expect(fehlt).not.toHaveBeenCalled();

        rerender({ rows: [zeile(msg(7))], key: 'k:INBOX', uid: 7, onOpen: geoeffnet, onFehlt: fehlt });

        expect(geoeffnet).toHaveBeenCalledTimes(1);
        expect(fehlt).not.toHaveBeenCalled();
        unmount();
    });

    /**
     * Einmal je Ordner. Ohne diese Grenze oeffnete der Haken nach jedem
     * Schliessen sofort wieder, und die Ansicht liesse sich nicht leeren.
     */
    it('oeffnet nur einmal je Ordner', () => {
        const geoeffnet = vi.fn();
        const zeilen = [zeile(msg(2))];
        const { rerender, unmount } = haenge({ rows: zeilen, key: 'k:INBOX', uid: 2, onOpen: geoeffnet });

        rerender({ rows: zeilen, key: 'k:INBOX', uid: 2, onOpen: geoeffnet });
        rerender({ rows: zeilen, key: 'k:INBOX', uid: 2, onOpen: geoeffnet });

        expect(geoeffnet).toHaveBeenCalledTimes(1);
        unmount();
    });

    it('tut nichts ohne verlangte Nachricht', () => {
        const geoeffnet = vi.fn();
        const fehlt = vi.fn();
        const { unmount } = haenge({
            rows: [zeile(msg(1))],
            key: 'k:INBOX',
            uid: null,
            onOpen: geoeffnet,
            onFehlt: fehlt,
        });

        expect(geoeffnet).not.toHaveBeenCalled();
        expect(fehlt).not.toHaveBeenCalled();
        unmount();
    });

    it('tut nichts, solange es abgeschaltet ist', () => {
        const geoeffnet = vi.fn();
        const { unmount } = haenge({
            rows: [zeile(msg(2))],
            key: 'k:INBOX',
            uid: 2,
            enabled: false,
            onOpen: geoeffnet,
        });

        expect(geoeffnet).not.toHaveBeenCalled();
        unmount();
    });
});
