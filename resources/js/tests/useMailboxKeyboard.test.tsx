import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { DisplayRow, RowMessage } from '../src/rows';
import { MAILBOX_KUERZEL, useMailboxKeyboard, type MailboxKeyboardOptions } from '../src/useMailboxKeyboard';
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

describe('die Hilfe sagt die Wahrheit (A4, #9116)', () => {
    /**
     * `MAILBOX_KUERZEL` steht neben den Bindungen, damit beide zusammen
     * geaendert werden. Dieser Test prueft, dass sie es auch sind.
     *
     * Der Anlass: Im Manager lag die Liste im Produkt, waehrend die Tasten
     * hier gebunden wurden. `Entf` war gebunden und fehlte in der Hilfe —
     * niemand haette das je bemerkt, denn eine Hilfe, die etwas verschweigt,
     * sieht genauso aus wie eine vollstaendige.
     *
     * Geprueft wird das VERHALTEN, nicht der Text: Jede Taste, die die Hilfe
     * nennt, muss etwas ausloesen.
     */
    const ausnahmen: Record<string, string> = {
        Esc: 'Escape schliesst Felder und Masken, nicht den Haken — es wird hier bewusst nicht als Tat gefuehrt.',
    };

    /**
     * „j / k" -> ['j','k'], „# / Entf" -> ['#','Delete'].
     *
     * Geteilt wird am Trenner MIT Leerzeichen. Ohne das zerfiel die Taste
     * „/" in zwei leere Stuecke — und der Test meldete, die Suche tue nichts,
     * obwohl sie es tat.
     */
    function tastenVon(beschriftung: string): string[] {
        return beschriftung
            .split(' / ')
            .map((teil) => teil.trim())
            .map((teil) => (teil === 'Entf' ? 'Delete' : teil));
    }

    it.each(MAILBOX_KUERZEL.filter(({ taste: t }) => !ausnahmen[t]).map(({ taste: t, bedeutung }) => [t, bedeutung]))(
        'die Taste %s (%s) loest etwas aus',
        (beschriftung) => {
            for (const key of tastenVon(String(beschriftung))) {
                const gerufen = vi.fn();
                const { unmount } = haenge({
                    openedUid: 2,
                    onOpen: gerufen,
                    onReply: gerufen,
                    onReplyAll: gerufen,
                    onForward: gerufen,
                    onArchive: gerufen,
                    onDelete: gerufen,
                    onToggleSeen: gerufen,
                    onToggleFlag: gerufen,
                    onSearch: gerufen,
                    onHelp: gerufen,
                });

                taste(key);

                expect(gerufen, `die Hilfe nennt „${key}", aber nichts passiert`).toHaveBeenCalled();
                unmount();
            }
        },
    );

    it('nennt jede Taste, die wirklich gebunden ist', () => {
        // Die Gegenrichtung: Eine gebundene Taste, die in der Hilfe fehlt, ist
        // genau der Fall, der `Entf` jahrelang verschwiegen hat.
        const genannt = new Set(MAILBOX_KUERZEL.flatMap(({ taste: t }) => tastenVon(t)));
        const gebunden = ['j', 'k', 'r', 'a', 'f', 'e', 'u', 's', '#', 'Delete', '/', '?'];

        expect(gebunden.filter((key) => !genannt.has(key))).toEqual([]);
    });
});
