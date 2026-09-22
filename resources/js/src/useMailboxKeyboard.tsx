import { useEffect, useRef } from 'react';
import { navigableRows } from './rows';
import type { DisplayRow, MessageHandle, RowMessage } from './rows';

/**
 * Die Tastatur im Postfach: j/k blaettern, r antwortet, e archiviert.
 *
 * ## Warum ins Paket
 *
 * `navigableRows` lag hier seit je — die Regel, welche Zeilen ueberhaupt
 * anspringbar sind (gespeicherte Antworten lassen sich nicht oeffnen). Die
 * Tastenbehandlung drumherum baute nur der Projekt-Manager. CRM und
 * Verwaltung hatten gar keine.
 *
 * ## Die zwei Regeln, die man leicht vergisst
 *
 * 1. **Wer tippt, meint Buchstaben.** In einem Eingabefeld, einer Textflaeche
 *    oder einem `contenteditable` darf `e` nicht archivieren. Das ist der
 *    Fehler, der eine Mail wegraeumt, waehrend jemand einen Betreff schreibt.
 * 2. **Modifikatoren gehoeren dem Browser.** `Strg+R` laedt neu; wer das
 *    abfaengt, nimmt dem Benutzer sein Werkzeug weg.
 *
 * `Escape` ist die Ausnahme von beidem: Es schliesst, was offen ist — auch aus
 * einem Eingabefeld heraus, denn genau dort will man es.
 *
 * ## Eine Taste ohne Rueckruf tut nichts
 *
 * Dieselbe Regel wie bei der Werkzeugleiste. Ein Produkt ohne Archiv-Weg soll
 * beim Druck auf `e` nichts passieren lassen — und nicht eine halbe Aktion.
 */
export interface MailboxKeyboardHandlers<M extends RowMessage> {
    /** j/k — die naechste oder vorige Zeile oeffnen. */
    onOpen?: (message: M) => void;
    onReply?: () => void;
    onReplyAll?: () => void;
    onForward?: () => void;
    onArchive?: () => void;
    onToggleSeen?: () => void;
    onToggleFlag?: () => void;
    onDelete?: () => void;
    /** `/` — ins Suchfeld springen. */
    onSearch?: () => void;
    /** `?` — die Tastaturhilfe zeigen. */
    onHelp?: () => void;
    /** Escape — schliessen, was offen ist. */
    onEscape?: () => void;
}

export interface MailboxKeyboardOptions<M extends RowMessage> extends MailboxKeyboardHandlers<M> {
    rows: DisplayRow<M>[];
    /** Die gerade geoeffnete Nachricht — Ausgangspunkt fuer j/k. */
    openedUid?: MessageHandle | null;
    /**
     * Solange false, wirkt nur Escape. Produkte schalten das ab, waehrend
     * jemand eine Antwort verfasst.
     */
    enabled?: boolean;
    /** Tasten, die nur mit geoeffneter Nachricht sinnvoll sind, brauchen das. */
    hasMessage?: boolean;
}

export function useMailboxKeyboard<M extends RowMessage>(optionen: MailboxKeyboardOptions<M>): void {
    /*
     * Spiegel der jeweils juengsten Fassung. Ohne ihn muesste der Zuhoerer bei
     * jedem Tastendruck ab- und wieder angemeldet werden — und zwischen den
     * beiden Schritten geht ein Anschlag verloren.
     */
    const aktuell = useRef(optionen);
    aktuell.current = optionen;

    useEffect(() => {
        function behandle(e: KeyboardEvent): void {
            const o = aktuell.current;
            const ziel = e.target as HTMLElement | null;
            const tippt =
                !!ziel &&
                (ziel.tagName === 'INPUT' || ziel.tagName === 'TEXTAREA' || ziel.isContentEditable || !!ziel.closest?.('.ProseMirror'));

            if (e.key === 'Escape') {
                o.onEscape?.();

                return;
            }

            if (tippt || e.metaKey || e.ctrlKey || e.altKey || o.enabled === false) {
                return;
            }

            const springe = (schritt: number) => {
                const zeilen = navigableRows(o.rows);

                if (zeilen.length === 0 || !o.onOpen) {
                    return;
                }

                const jetzt = o.openedUid == null ? -1 : zeilen.findIndex((r) => r.msg.uid === o.openedUid);
                const roh = jetzt === -1 ? (schritt > 0 ? 0 : zeilen.length - 1) : jetzt + schritt;
                const naechste = zeilen[Math.max(0, Math.min(zeilen.length - 1, roh))];

                if (naechste) {
                    o.onOpen(naechste.msg);
                }
            };

            // Nur was einen Rueckruf hat, faengt die Taste ab. Sonst bliebe das
            // Zeichen beim Browser haengen, ohne dass etwas passiert.
            const mitNachricht = (fn?: () => void) => (o.hasMessage && fn ? fn : undefined);

            const taten: Record<string, (() => void) | undefined> = {
                j: o.onOpen ? () => springe(1) : undefined,
                k: o.onOpen ? () => springe(-1) : undefined,
                r: mitNachricht(o.onReply),
                a: mitNachricht(o.onReplyAll),
                f: mitNachricht(o.onForward),
                e: mitNachricht(o.onArchive),
                u: mitNachricht(o.onToggleSeen),
                s: mitNachricht(o.onToggleFlag),
                '#': mitNachricht(o.onDelete),
                Delete: mitNachricht(o.onDelete),
                '/': o.onSearch,
                '?': o.onHelp,
            };

            const tat = taten[e.key];

            if (tat) {
                e.preventDefault();
                tat();
            }
        }

        window.addEventListener('keydown', behandle);

        return () => window.removeEventListener('keydown', behandle);
    }, []);
}
