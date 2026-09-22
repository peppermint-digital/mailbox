import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { DisplayRow, RowMessage } from '../src/rows';
import { useMailboxKeyboard, type MailboxKeyboardOptions } from '../src/useMailboxKeyboard';
import { render } from './support/render';

/**
 * Die Tastatur im Postfach (22.09.2026).
 *
 * Die beiden Regeln, die hier wirklich zaehlen, sind die, deren Verletzung
 * niemand als Fehler meldet — sie sieht aus wie ein Ausrutscher des Benutzers:
 *
 * - Wer in einem Feld tippt, meint Buchstaben. `e` darf dort nicht
 *   archivieren.
 * - `Strg+R` gehoert dem Browser.
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

const zeilen: DisplayRow<RowMessage>[] = [1, 2, 3].map((u) => ({ msg: msg(u), key: `k${u}`, count: 1, isMember: false }));

function haenge(optionen: Partial<MailboxKeyboardOptions<RowMessage>>) {
    function Probe() {
        useMailboxKeyboard({ rows: zeilen, hasMessage: true, ...optionen });

        return null;
    }

    return render(<Probe />);
}

function taste(key: string, init: KeyboardEventInit = {}, ziel: EventTarget = window) {
    act(() => {
        ziel.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init }));
    });
}

describe('useMailboxKeyboard', () => {
    it('blaettert mit j und k durch die Liste', () => {
        const geoeffnet: number[] = [];
        const { unmount } = haenge({ openedUid: 2, onOpen: (m) => geoeffnet.push(m.uid as number) });

        taste('j');
        taste('k');

        expect(geoeffnet).toEqual([3, 1]);
        unmount();
    });

    it('faengt bei j ganz vorn an, wenn nichts offen ist', () => {
        const geoeffnet: number[] = [];
        const { unmount } = haenge({ openedUid: null, onOpen: (m) => geoeffnet.push(m.uid as number) });

        taste('j');

        expect(geoeffnet).toEqual([1]);
        unmount();
    });

    it('bleibt am Ende stehen, statt umzuspringen', () => {
        // Ein Sprung von der letzten zur ersten Zeile sieht aus, als waere ein
        // Anschlag verlorengegangen.
        const geoeffnet: number[] = [];
        const { unmount } = haenge({ openedUid: 3, onOpen: (m) => geoeffnet.push(m.uid as number) });

        taste('j');

        expect(geoeffnet).toEqual([3]);
        unmount();
    });

    it('laesst Buchstaben in Eingabefeldern in Ruhe', () => {
        // Der Fehler, der eine Mail wegraeumt, waehrend jemand einen Betreff
        // schreibt.
        const archiviere = vi.fn();
        const { unmount } = haenge({ onArchive: archiviere });

        const feld = document.createElement('input');
        document.body.appendChild(feld);
        taste('e', {}, feld);

        expect(archiviere).not.toHaveBeenCalled();
        feld.remove();
        unmount();
    });

    it('laesst auch contenteditable in Ruhe', () => {
        const archiviere = vi.fn();
        const { unmount } = haenge({ onArchive: archiviere });

        const bereich = document.createElement('div');
        bereich.contentEditable = 'true';
        Object.defineProperty(bereich, 'isContentEditable', { value: true });
        document.body.appendChild(bereich);
        taste('e', {}, bereich);

        expect(archiviere).not.toHaveBeenCalled();
        bereich.remove();
        unmount();
    });

    it('ueberlaesst Strg und Befehl dem Browser', () => {
        // Wer `Strg+R` abfaengt, nimmt dem Benutzer das Neuladen weg.
        const antworte = vi.fn();
        const { unmount } = haenge({ onReply: antworte });

        taste('r', { ctrlKey: true });
        taste('r', { metaKey: true });

        expect(antworte).not.toHaveBeenCalled();
        unmount();
    });

    it('laesst Escape auch aus einem Eingabefeld durch', () => {
        // Genau dort will man es: Das Feld schliessen, in dem man steht.
        const schliesse = vi.fn();
        const { unmount } = haenge({ onEscape: schliesse });

        const feld = document.createElement('input');
        document.body.appendChild(feld);
        taste('Escape', {}, feld);

        expect(schliesse).toHaveBeenCalled();
        feld.remove();
        unmount();
    });

    it('schweigt bei Tasten, fuer die es keinen Rueckruf gibt', () => {
        // Ein Produkt ohne Archiv-Weg soll bei `e` nichts tun — und nicht eine
        // halbe Aktion.
        const { unmount } = haenge({});

        const ereignis = new KeyboardEvent('keydown', { key: 'e', bubbles: true, cancelable: true });
        act(() => { window.dispatchEvent(ereignis); });

        expect(ereignis.defaultPrevented).toBe(false);
        unmount();
    });

    it('haelt nachrichtengebundene Tasten zurueck, solange keine offen ist', () => {
        const archiviere = vi.fn();
        const { unmount } = haenge({ hasMessage: false, onArchive: archiviere });

        taste('e');

        expect(archiviere).not.toHaveBeenCalled();
        unmount();
    });

    it('schweigt ganz, solange jemand verfasst — ausser bei Escape', () => {
        const archiviere = vi.fn();
        const schliesse = vi.fn();
        const { unmount } = haenge({ enabled: false, onArchive: archiviere, onEscape: schliesse });

        taste('e');
        taste('Escape');

        expect(archiviere).not.toHaveBeenCalled();
        expect(schliesse).toHaveBeenCalled();
        unmount();
    });

    it('belegt die uebrigen Tasten wie gewohnt', () => {
        const gerufen: string[] = [];
        const { unmount } = haenge({
            onReply: () => gerufen.push('r'),
            onReplyAll: () => gerufen.push('a'),
            onForward: () => gerufen.push('f'),
            onToggleSeen: () => gerufen.push('u'),
            onToggleFlag: () => gerufen.push('s'),
            onDelete: () => gerufen.push('del'),
            onSearch: () => gerufen.push('/'),
            onHelp: () => gerufen.push('?'),
        });

        ['r', 'a', 'f', 'u', 's', 'Delete', '/', '?'].forEach((k) => taste(k));

        expect(gerufen).toEqual(['r', 'a', 'f', 'u', 's', 'del', '/', '?']);
        unmount();
    });
});
