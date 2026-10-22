import { describe, expect, it, vi } from 'vitest';
import { MailboxFlaeche } from '../src/MailboxFlaeche';
import type { MailboxSeiteErgebnis } from '../src/useMailboxSeite';
import { render } from './support/render';

/**
 * Die eine Fläche der Mailseite (#8377).
 *
 * Geprüft wird, was still falsch sein kann: Landen die vier Einschnitte an der
 * richtigen Stelle? Zeigt eine Seite ohne Postfach eine ERKLÄRUNG statt eines
 * leeren Browsers? Und — der Fall, der eine weiße Fläche erzeugen würde —
 * kommt die Leseansicht zurück, wenn ein Produkt kein Antwortfeld mitgibt?
 */
function seite(teil: Partial<MailboxSeiteErgebnis> = {}): MailboxSeiteErgebnis {
    const leer = {
        amGeoeffneten: vi.fn(),
        antwortAnAlle: false,
        antwortOffen: false,
        antwortSenden: vi.fn(),
        archivKennungen: [],
        assistent: { sichtbar: false, zeigen: vi.fn(), verbergen: vi.fn() },
        auswahl: { ticked: [], targets: [], toggle: vi.fn(), clear: vi.fn() },
        beschaeftigt: false,
        chatQuelle: null,
        csrf: 'abc',
        fehler: null,
        filterOffen: false,
        konto: { id: 1, email: 'post@beispiel.de', name: 'Post' },
        liste: {
            load: vi.fn(),
            page: 1,
            total: 0,
            totalPages: 1,
            loading: false,
            refreshing: false,
            grouped: false,
            setGrouped: vi.fn(),
        },
        nachrichtBeschaeftigt: false,
        offene: { message: null, loading: false, open: vi.fn(), close: vi.fn() },
        offeneKennung: () => 1,
        ordner: { folders: [], loading: false },
        ordnerPfad: 'INBOX',
        ordnerSichtbar: true,
        ordnerVerwaltung: {
            menu: { open: false, x: 0, y: 0, folder: null },
            setMenu: vi.fn(),
            dialog: { open: false, mode: 'create', name: '', path: '' },
            setDialog: vi.fn(),
            submit: vi.fn(),
            deleteTarget: null,
            setDeleteTarget: vi.fn(),
            confirmDelete: vi.fn(),
            busy: false,
        },
        sammelAktion: vi.fn(),
        schlummern: vi.fn(),
        sendetAntwort: false,
        setAntwortAnAlle: vi.fn(),
        setAntwortOffen: vi.fn(),
        setFehler: vi.fn(),
        setFilterOffen: vi.fn(),
        setKonto: vi.fn(),
        setOrdnerPfad: vi.fn(),
        setOrdnerSichtbar: vi.fn(),
        setWeiterleitenOffen: vi.fn(),
        sichtbareZeilen: (z: unknown) => z,
        suche: {
            criteria: { query: '', from: '', subject: '', since: '', unseen: false, allFolders: false },
            canSearch: true,
            searching: false,
            isSearchMode: false,
            foldersSearched: 0,
            totalFound: 0,
            set: vi.fn(),
            search: vi.fn(),
            clear: vi.fn(),
        },
        verlauf: { entries: [], loading: false, open: false, toggle: vi.fn(), thread: null },
        weiterleitenOffen: false,
        werkzeuge: {
            werkzeuge: [],
            unerreichbar: [],
            gescheitert: [],
            laedt: false,
            leerAberVollstaendig: true,
            erreicht: true,
            ausloesen: vi.fn(),
        },
        zeilen: [],
        zuweisungen: { users: [], erreichbar: true, busy: null, fuer: () => null, zuweisen: vi.fn(), aufheben: vi.fn() },
    };

    return { ...leer, ...teil } as never as MailboxSeiteErgebnis;
}

const KONTO = { id: 1, email: 'post@beispiel.de', name: 'Post' };

/**
 * Eine geöffnete Nachricht in der Form, die die Leseansicht wirklich liest.
 *
 * `to` und `cc` sind Pflicht — `MailMessageView` zählt sie, um „weitere
 * Empfänger" zu entscheiden. Ein Brief ohne die beiden Felder wirft, und das
 * ist richtig so: Eine Nachricht ohne Empfängerlisten gibt es nicht.
 */
function nachricht(teil: Record<string, unknown> = {}) {
    return { uid: 1, subject: 'Angebot', to: [], cc: [], attachments: [], source: 'imap', ...teil };
}

function flaeche(props: Partial<Parameters<typeof MailboxFlaeche>[0]> = {}) {
    return render(
        <MailboxFlaeche
            seite={props.seite ?? seite()}
            konten={props.konten ?? [KONTO]}
            navigieren={props.navigieren ?? vi.fn()}
            {...props}
        />,
    );
}

describe('eine Seite ohne Postfach', () => {
    it('erklaert es, statt einen leeren Browser zu zeigen', () => {
        /*
         * Ein leerer Mail-Browser sieht aus wie ein Defekt. Die Erklaerung ist
         * der Unterschied zwischen „kaputt" und „dir ist keines zugeteilt" —
         * und genau das war am 21.10.2026 die Frage des Nutzers.
         */
        const { container, unmount } = flaeche({ konten: [] });

        expect(container.textContent).toContain('noch kein Postfach zugeteilt');
        unmount();
    });

    it('nimmt den Satz des Produkts, wenn es einen mitgibt', () => {
        const { container, unmount } = flaeche({ konten: [], leerText: 'Hier ist nichts.' });

        expect(container.textContent).toContain('Hier ist nichts.');
        expect(container.textContent).not.toContain('zugeteilt');
        unmount();
    });
});

describe('die vier Einschnitte', () => {
    it('zeichnet `vorDerAnsicht` in der Ansicht', () => {
        const { container, unmount } = flaeche({ vorDerAnsicht: <p>Kontaktleiste</p> });

        expect(container.textContent).toContain('Kontaktleiste');
        unmount();
    });

    it('zeichnet `nachDerSeite` ausserhalb des Hoehen-Kastens', () => {
        /*
         * Ein Dialog gehoert NICHT in den Kasten mit `overflow-hidden`: Dort
         * waere er beschnitten. Deshalb steht er hinter ihm.
         */
        const { container, unmount } = flaeche({ nachDerSeite: <p data-slot="dialog">Entfernen?</p> });

        const kasten = container.querySelector('.overflow-hidden');
        const dialog = container.querySelector('[data-slot="dialog"]');

        expect(dialog).not.toBeNull();
        expect(kasten?.contains(dialog!)).toBe(false);
        unmount();
    });

    it('reicht `verbergen` und `eigene` an die Werkzeugleiste durch', () => {
        const verbergen = vi.fn(() => true);

        const { unmount } = flaeche({
            seite: seite({
                werkzeuge: {
                    werkzeuge: [{ key: 'ticket', label: 'Ticket', icon: null, order: 10, group: null, fields: null, confirm: null, state: null, url: null, system: null, system_label: null }],
                    unerreichbar: [],
                    gescheitert: [],
                    laedt: false,
                    leerAberVollstaendig: false,
                    erreicht: true,
                    ausloesen: vi.fn(),
                } as never,
            }),
            werkzeugeVerbergen: verbergen,
        });

        expect(verbergen).toHaveBeenCalled();
        unmount();
    });
});

describe('Antworten und Weiterleiten', () => {
    it('zeigt das Antwortfeld des Produkts, wenn geantwortet wird', () => {
        const { container, unmount } = flaeche({
            seite: seite({ antwortOffen: true, offene: { message: nachricht(), loading: false, open: vi.fn(), close: vi.fn() } as never }),
            antwortFeld: () => <p>Antwortfeld</p>,
        });

        expect(container.textContent).toContain('Antwortfeld');
        unmount();
    });

    it('laesst das Weiterleiten gewinnen, wenn beides offen ist', () => {
        /*
         * Die Reihenfolge der Faelle ist eine Entscheidung: Wer gerade
         * weiterleitet, soll nicht in ein Antwortfeld springen, weil im
         * Zustand noch `antwortOffen` steht.
         */
        const { container, unmount } = flaeche({
            seite: seite({
                antwortOffen: true,
                weiterleitenOffen: true,
                offene: { message: nachricht(), loading: false, open: vi.fn(), close: vi.fn() } as never,
            }),
            antwortFeld: () => <p>Antwortfeld</p>,
            weiterleitenFeld: () => <p>Weiterleitenfeld</p>,
        });

        expect(container.textContent).toContain('Weiterleitenfeld');
        expect(container.textContent).not.toContain('Antwortfeld');
        unmount();
    });

    it('faellt auf die Leseansicht zurueck, wenn das Produkt kein Feld mitgibt', () => {
        /*
         * Der Fall, der eine WEISSE Flaeche erzeugen wuerde: Ein Produkt ohne
         * Antwortfeld, bei dem der Zustand trotzdem auf „offen" steht. Lieber
         * die Leseansicht als nichts.
         */
        const { container, unmount } = flaeche({
            seite: seite({
                antwortOffen: true,
                offene: { message: nachricht(), loading: false, open: vi.fn(), close: vi.fn() } as never,
            }),
        });

        expect(container.textContent).toContain('Angebot');
        unmount();
    });
});

describe('der Postfach-Assistent', () => {
    it('bleibt weg, solange das Produkt ihn nicht hat', () => {
        const { container, unmount } = flaeche({ chatVerfuegbar: false });

        expect(container.textContent).not.toContain('AI-Brain-Chat');
        unmount();
    });

    it('zeigt den Griff, wenn er verfuegbar aber zugeklappt ist', () => {
        const { container, unmount } = flaeche({ chatVerfuegbar: true });

        expect(container.textContent).toContain('AI-Brain-Chat');
        unmount();
    });
});
