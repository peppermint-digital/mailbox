import { describe, expect, it, vi } from 'vitest';
import { MailListToolbar, type MailListToolbarLabels, type MailListToolbarSearch } from '../src/MailListToolbar';
import { act } from 'react';
import { render, textOf } from './support/render';

/** Radix-Menues reagieren auf `pointerdown`; ein Klick allein oeffnet nichts. */
function oeffne(element: Element): void {
    act(() => {
        element.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true, button: 0 }));
    });
}

/**
 * Die Leiste ueber den Nachrichten (#22.09.2026).
 *
 * Geprueft wird nicht, wie sie aussieht — das entscheidet Tailwind. Geprueft
 * wird, was sie ZEIGT und was sie VERSCHWEIGT: Jede Entscheidung hier kann
 * still falsch sein, ohne dass etwas ausfaellt. Eine Blaetterung, die bei
 * Suchtreffern stehenbleibt, blaettert in einer Liste, die es nicht gibt. Ein
 * Verschieben-Knopf ohne Rueckmeldung sieht aus wie einer, der funktioniert.
 */
const texte: MailListToolbarLabels = {
    toggleFolders: (gezeigt) => (gezeigt ? 'Ordner ausblenden' : 'Ordner einblenden'),
    searchPlaceholder: 'E-Mails durchsuchen...',
    clearSearch: 'Suche zurücksetzen',
    filters: 'Filter',
    search: 'Suchen',
    searching: 'Suche läuft …',
    filterFrom: 'Von',
    filterFromPlaceholder: 'absender@…',
    filterSubject: 'Betreff',
    filterSubjectPlaceholder: 'Betreff enthält…',
    filterSince: 'Datum ab',
    filterUnseen: 'Nur ungelesene',
    filterAllFolders: 'In allen Ordnern',
    filterAllFoldersHint: '(ohne Papierkorb, Spam, Entwürfe)',
    reset: 'Zurücksetzen',
    searchResultsFor: (q) => `Suchergebnisse für "${q}"`,
    searchResultsFiltered: 'Suchergebnisse (Filter)',
    back: 'Zurück',
    groupByThread: 'Nach Konversation gruppieren',
    groupDisabledInSearch: 'Suchtreffer werden einzeln gezeigt',
    loading: 'Laden...',
    countMessages: (n, gruppiert) => `${n} ${gruppiert ? 'Konversationen' : 'E-Mails'}`,
    countHits: (gezeigt, gefunden, ordner) =>
        `${gezeigt}${gefunden > gezeigt ? ` von ${gefunden}` : ''} Treffer${ordner > 1 ? ` in ${ordner} Ordnern` : ''}`,
    selected: (n) => `${n} ausgewählt`,
    alsoAffects: (n) => `${n} Nachrichten`,
    markRead: 'Gelesen',
    markUnread: 'Ungelesen',
    archive: 'Archivieren',
    archiveTitle: (n) => `${n} Nachricht(en) archivieren`,
    move: 'Verschieben',
    moveTitle: 'In Ordner verschieben',
    delete: 'Löschen',
    clearSelection: 'Auswahl aufheben',
    hilfe: 'Tastatur-Kürzel (?)',
    verzeichnis: (kopfzeilen, ordner, minutenHer) =>
        `Aus dem Verzeichnis: ${kopfzeilen} Kopfzeilen aus ${ordner} Ordnern` +
        (minutenHer === null ? '' : `, zuletzt vor ${minutenHer} Minuten erneuert`),
    verzeichnisVeraltet: 'veraltet',
};

function suche(ueberschrieben: Partial<MailListToolbarSearch> = {}): MailListToolbarSearch {
    return {
        query: '',
        from: '',
        subject: '',
        since: '',
        unseen: false,
        allFolders: true,
        canSearch: false,
        searching: false,
        isSearchMode: false,
        foldersSearched: 0,
        totalFound: 0,
        set: vi.fn(),
        run: vi.fn(),
        clear: vi.fn(),
        ...ueberschrieben,
    };
}

const grund = { labels: texte, total: 42, page: 1, totalPages: 1 };

describe('MailListToolbar', () => {
    it('zeigt die Anzahl im Wort des jeweiligen Modus', () => {
        const { container, rerender, unmount } = render(<MailListToolbar {...grund} search={suche()} />);
        expect(textOf(container)).toContain('42 E-Mails');

        rerender(<MailListToolbar {...grund} search={suche()} grouped onToggleGrouped={() => {}} />);
        expect(textOf(container)).toContain('42 Konversationen');
        unmount();
    });

    it('nennt bei Treffern auch die Gesamtzahl und die Ordner', () => {
        // Ohne diese Zahlen sieht eine Suche, die 12 von 300 Treffern in einem
        // von sieben Ordnern zeigt, aus wie eine vollstaendige Antwort.
        const { container, unmount } = render(
            <MailListToolbar {...grund} total={12} search={suche({ isSearchMode: true, totalFound: 300, foldersSearched: 7 })} />,
        );

        expect(textOf(container)).toContain('12 von 300 Treffer in 7 Ordnern');
        unmount();
    });

    it('blendet die Blaetterung bei Suchtreffern aus', () => {
        // Treffer stammen aus vielen Ordnern; „Seite 2 von 5" haette dort
        // keinen Gegenstand. Der Knopf wuerde trotzdem klickbar aussehen.
        const { container, rerender, unmount } = render(
            <MailListToolbar {...grund} totalPages={5} onPage={() => {}} search={suche()} />,
        );
        expect(container.querySelector('[data-slot="mail-list-pagination"]')).not.toBeNull();

        rerender(<MailListToolbar {...grund} totalPages={5} onPage={() => {}} search={suche({ isSearchMode: true })} />);
        expect(container.querySelector('[data-slot="mail-list-pagination"]')).toBeNull();
        unmount();
    });

    it('sperrt die Konversations-Umschaltung waehrend der Suche und sagt warum', () => {
        const { container, unmount } = render(
            <MailListToolbar {...grund} onToggleGrouped={() => {}} search={suche({ isSearchMode: true })} />,
        );

        const knopf = container.querySelector<HTMLButtonElement>('button[aria-label="Nach Konversation gruppieren"]')!;
        expect(knopf.disabled).toBe(true);
        expect(knopf.title).toBe('Suchtreffer werden einzeln gezeigt');
        unmount();
    });

    it('zeigt die Sammel-Leiste erst mit einer Auswahl', () => {
        const { container, rerender, unmount } = render(<MailListToolbar {...grund} search={suche()} onMarkRead={() => {}} />);
        expect(container.querySelector('[data-slot="mail-list-bulk"]')).toBeNull();

        rerender(<MailListToolbar {...grund} search={suche()} selected={new Set([1, 2])} onMarkRead={() => {}} />);
        expect(container.querySelector('[data-slot="mail-list-bulk"]')).not.toBeNull();
        expect(textOf(container)).toContain('2 ausgewählt');
        unmount();
    });

    it('sagt vor dem Klick, dass eine Kette mehr trifft als angehakt ist', () => {
        // Zwei Haken auf Konversationen koennen neun Nachrichten archivieren.
        // Wer das erst hinterher merkt, hat neun Nachrichten verschoben.
        const { container, unmount } = render(
            <MailListToolbar {...grund} search={suche()} selected={new Set([1, 2])} affected={9} onArchive={() => {}} />,
        );

        expect(textOf(container)).toContain('2 ausgewählt');
        expect(textOf(container)).toContain('9 Nachrichten');
        unmount();
    });

    it('schweigt ueber die Kettenzahl, wenn sie der Auswahl entspricht', () => {
        const { container, unmount } = render(
            <MailListToolbar {...grund} search={suche()} selected={new Set([1, 2])} affected={2} onArchive={() => {}} />,
        );

        expect(textOf(container)).not.toContain('2 Nachrichten');
        unmount();
    });

    it('laesst eine Sammel-Aktion weg, fuer die es keinen Empfaenger gibt', () => {
        // Ein Produkt ohne Loeschweg soll keinen Loeschknopf zeigen, der nichts
        // tut — ein Knopf ohne Wirkung ist schlimmer als keiner.
        const { container, unmount } = render(<MailListToolbar {...grund} search={suche()} selected={new Set([1])} onMarkRead={() => {}} />);

        expect(textOf(container)).toContain('Gelesen');
        expect(textOf(container)).not.toContain('Löschen');
        expect(textOf(container)).not.toContain('Verschieben');
        unmount();
    });

    it('meldet das Oeffnen des Verschieben-Menues, damit Ordner nachgeladen werden', () => {
        const geoeffnet = vi.fn();
        const { container, unmount } = render(
            <MailListToolbar {...grund} search={suche()} selected={new Set([1])} onMove={() => {}} onMoveMenuOpen={geoeffnet} moveTargets={[]} />,
        );

        // Radix oeffnet bei `pointerdown`, nicht bei `click`. Ein Klick-Ereignis
        // laesst das Menue zu — und der Test faende einen Fehler, den es nicht
        // gibt, oder uebersaehe einen, den es gibt.
        oeffne(container.querySelector('button[title="In Ordner verschieben"]')!);
        expect(geoeffnet).toHaveBeenCalled();
        unmount();
    });

    it('haelt den Suchknopf zu, solange die Eingabe nichts einschraenkt', () => {
        const { container, rerender, unmount } = render(<MailListToolbar {...grund} search={suche({ canSearch: false })} />);
        expect(container.querySelector<HTMLButtonElement>('button[aria-label="Suchen"]')!.disabled).toBe(true);

        rerender(<MailListToolbar {...grund} search={suche({ canSearch: true })} />);
        expect(container.querySelector<HTMLButtonElement>('button[aria-label="Suchen"]')!.disabled).toBe(false);
        unmount();
    });

    it('zeigt das Loeschkreuz der Suche erst, wenn es etwas zu loeschen gibt', () => {
        const { container, rerender, unmount } = render(<MailListToolbar {...grund} search={suche()} />);
        expect(container.querySelector('button[aria-label="Suche zurücksetzen"]')).toBeNull();

        rerender(<MailListToolbar {...grund} search={suche({ query: 'Rechnung' })} />);
        expect(container.querySelector('button[aria-label="Suche zurücksetzen"]')).not.toBeNull();
        unmount();
    });

    it('klappt die Filter nur auf Geheiss auf', () => {
        const { container, rerender, unmount } = render(<MailListToolbar {...grund} search={suche()} onToggleFilters={() => {}} />);
        expect(container.querySelector('[data-slot="mail-list-filters"]')).toBeNull();

        rerender(<MailListToolbar {...grund} search={suche()} onToggleFilters={() => {}} filtersOpen />);
        expect(container.querySelector('[data-slot="mail-list-filters"]')).not.toBeNull();
        expect(textOf(container)).toContain('Betreff');
        unmount();
    });

    it('nennt im Banner die gesuchten Worte — und sonst, dass Filter es waren', () => {
        const { container, rerender, unmount } = render(<MailListToolbar {...grund} search={suche({ isSearchMode: true, query: 'Angebot' })} />);
        expect(textOf(container)).toContain('Suchergebnisse für "Angebot"');

        rerender(<MailListToolbar {...grund} search={suche({ isSearchMode: true })} />);
        expect(textOf(container)).toContain('Suchergebnisse (Filter)');
        unmount();
    });

    /*
     * Hier stand bis zum 30.10.2026 ein Test fuer `extras` — ein Steckplatz
     * fuer eigene Knoepfe des Produkts. Was darin lag, liegt seit A3/A12
     * (#9116) in dieser Leiste: der Zustaendigkeits-Filter und dieser Knopf.
     */
    it('zeigt den Knopf zur Tastatur-Hilfe nur, wenn er auch etwas aufmacht', () => {
        const { container, rerender, unmount } = render(<MailListToolbar {...grund} search={suche()} />);
        expect(container.querySelector('[data-slot="mail-list-hilfe"]')).toBeNull();

        const oeffnen = vi.fn();
        rerender(<MailListToolbar {...grund} search={suche()} onHilfe={oeffnen} />);

        const knopf = container.querySelector<HTMLElement>('[data-slot="mail-list-hilfe"]');
        expect(knopf).not.toBeNull();

        // Die Taste gehoert in die Aufschrift: Eine Hilfe, die man nur mit `?`
        // erreicht, findet niemand — und das war der Grund fuer diesen Knopf.
        expect(knopf?.getAttribute('title')).toBe('Tastatur-Kürzel (?)');

        knopf?.click();
        expect(oeffnen).toHaveBeenCalledTimes(1);
        unmount();
    });

    it('sagt, wie alt das Suchverzeichnis ist — aber nur im Suchmodus', () => {
        const verzeichnis = { entries: 412, folders: 7, minutes_ago: 3, stale: false };

        // Ausserhalb der Suche kommt die Liste aus dem Postfach; das
        // Verzeichnis hat dazu nichts zu melden.
        const { container, rerender, unmount } = render(<MailListToolbar {...grund} search={suche()} verzeichnis={verzeichnis} />);
        expect(container.querySelector('[data-slot="mail-list-verzeichnis"]')).toBeNull();

        rerender(<MailListToolbar {...grund} search={suche({ isSearchMode: true })} verzeichnis={verzeichnis} />);

        // Die ZAHLEN, nicht nur die Zeile: Ein Hinweis mit den falschen Zahlen
        // waere genauso kaputt wie keiner.
        expect(textOf(container)).toContain('412 Kopfzeilen aus 7 Ordnern');
        expect(textOf(container)).toContain('zuletzt vor 3 Minuten erneuert');
        expect(textOf(container)).not.toContain('veraltet');

        // Ohne Durchlauf keine Zeitangabe — „vor null Minuten" waere eine
        // Frische, die es nicht gibt.
        rerender(
            <MailListToolbar
                {...grund}
                search={suche({ isSearchMode: true })}
                verzeichnis={{ ...verzeichnis, minutes_ago: null, stale: true }}
            />,
        );
        expect(textOf(container)).toContain('412 Kopfzeilen aus 7 Ordnern');
        expect(textOf(container)).not.toContain('erneuert');
        expect(textOf(container)).toContain('veraltet');

        // Ohne Angabe sagt die Leiste gar nichts — drei der vier Produkte
        // fuehren kein Verzeichnis.
        rerender(<MailListToolbar {...grund} search={suche({ isSearchMode: true })} />);
        expect(container.querySelector('[data-slot="mail-list-verzeichnis"]')).toBeNull();
        unmount();
    });

    it('zeigt den Ordner-Umschalter nur, wenn das Produkt ihn bedient', () => {
        const { container, rerender, unmount } = render(<MailListToolbar {...grund} search={suche()} />);
        expect(container.querySelector('button[title="Ordner ausblenden"]')).toBeNull();

        rerender(<MailListToolbar {...grund} search={suche()} onToggleFolders={() => {}} />);
        expect(container.querySelector('button[title="Ordner ausblenden"]')).not.toBeNull();

        rerender(<MailListToolbar {...grund} search={suche()} onToggleFolders={() => {}} showFolders={false} />);
        expect(container.querySelector('button[title="Ordner einblenden"]')).not.toBeNull();
        unmount();
    });

    it('zeigt einen Hinweis neben der Zahl, wenn eine Nebenauskunft fehlte', () => {
        /*
         * „Nicht geholt" und „niemand zustaendig" ergeben dieselbe leere
         * Anzeige. Seit die Zustaendigkeit in der Mitte liegt, ist das ein
         * echter Unterschied — und ohne diesen Hinweis saehe jede Mail frei
         * aus, obwohl jemand an ihr arbeitet.
         */
        const { container, rerender, unmount } = render(<MailListToolbar {...grund} search={suche()} />);

        expect(container.querySelector('[data-slot="mail-list-hint"]')).toBeNull();

        rerender(<MailListToolbar {...grund} search={suche()} hint="Zuständigkeiten nicht erreichbar" />);

        const hinweis = container.querySelector('[data-slot="mail-list-hint"]');

        expect(hinweis?.textContent).toContain('nicht erreichbar');
        // Der Satz steht auch im Titel: Die Zeile wird abgeschnitten, wenn
        // die Leiste eng ist, und dann ist er nur noch dort lesbar.
        expect(hinweis?.getAttribute('title')).toBe('Zuständigkeiten nicht erreichbar');

        unmount();
    });
});
