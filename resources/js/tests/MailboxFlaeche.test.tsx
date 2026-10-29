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
        // Der Routen-Vorsatz (#9116). Bewusst in der Attrappe und NICHT als
        // Rueckfall in der Flaeche: Fehlt er wirklich, baut die Flaeche
        // `undefined/1/forward` — eine Adresse, die sofort auffaellt. Ein
        // stiller Rueckfall auf `/mailbox` haette im Projekt-Manager
        // plausibel aussehende 404 erzeugt.
        basis: '/mailbox',
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
        melden: vi.fn(),
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

    it('baut die Eigenschaften des Weiterleiten-Felds selbst — aus Ordner und Kennung', () => {
        /*
         * Der Grund, warum die Flaeche sie baut und nicht das Produkt: Hier
         * kann ein Fehler STILL sein. Ein falscher `uid` schickt eine andere
         * Mail weiter, und das Formular sieht dabei genauso aus. Deshalb wird
         * nicht geprueft, DASS etwas gezeichnet wird, sondern WOMIT.
         */
        const gesehen: Record<string, unknown>[] = [];

        const { unmount } = flaeche({
            seite: seite({
                weiterleitenOffen: true,
                ordnerPfad: 'Gesendete Elemente',
                offeneKennung: () => 46807,
                offene: { message: nachricht(), loading: false, open: vi.fn(), close: vi.fn() } as never,
            }),
            weiterleitenFeld: (eigenschaften: Record<string, unknown>) => {
                gesehen.push(eigenschaften);

                return <p>Weiterleitenfeld</p>;
            },
        });

        expect(gesehen).toHaveLength(1);
        expect(gesehen[0].source).toEqual({ type: 'imap', folder: 'Gesendete Elemente', uid: 46807 });
        expect(gesehen[0].endpoints).toEqual({ forward: '/mailbox/1/forward' });
        expect(gesehen[0].account).toEqual({ id: 1, name: 'Post', email: 'post@beispiel.de' });
        unmount();
    });

    it('baut die Adresse des Weiterleiten-Felds unter dem Vorsatz des Produkts (#9116)', () => {
        /*
         * Die Flaeche hatte `/mailbox/...` fest verdrahtet, an acht Stellen.
         * Der Projekt-Manager liegt unter `/emails/...` — hier wird die eine
         * Adresse geprueft, die ein Produkt wirklich hereinbekommt.
         */
        const feld = vi.fn(() => null);

        render(
            <MailboxFlaeche
                seite={seite({ basis: '/emails', weiterleitenOffen: true, offene: { message: { uid: 1 }, loading: false } as never })}
                konten={[{ id: 1, email: 'a@b.test', name: 'A' }]}
                navigieren={() => {}}
                weiterleitenFeld={feld as never}
            />,
        );

        expect((feld.mock.calls[0]?.[0] as { endpoints?: { forward?: string } })?.endpoints).toEqual({ forward: '/emails/1/forward' });
    });

    it('reicht die Zusatzknoepfe des Produkts an den Assistenten durch (#9116)', async () => {
        /*
         * `MailboxChat` nimmt `controls` seit jeher — die Flaeche hat es nur
         * nicht weitergegeben. Folge: Der Projekt-Manager haette beim Umstieg
         * auf diese Flaeche seine Spracheingabe verloren, und damit einen
         * Grund gehabt, die Seite doch selbst zu bauen.
         *
         * Geprueft wird an der ECHTEN Verdrahtung, nicht an einer Attrappe
         * von MailboxChat: Eine Attrappe wuerde zeigen, dass die Flaeche
         * etwas weitergibt — nicht, dass es unten ankommt.
         */
        const chatQuelle = {
            load: async () => ({ messages: [], status: null, activity: [] }),
            send: async () => null,
        };

        const { container } = render(
            <MailboxFlaeche
                seite={seite({
                    chatQuelle: chatQuelle as never,
                    assistent: { sichtbar: true, zeigen: vi.fn(), verbergen: vi.fn() },
                })}
                konten={[{ id: 1, email: 'a@b.test', name: 'A' }]}
                navigieren={() => {}}
                chatVerfuegbar
                chatZusatz={() => <button data-slot="probe-mikrofon">Mikrofon</button>}
            />,
        );

        await vi.waitFor(() => expect(container.querySelector('[data-slot="probe-mikrofon"]')).not.toBeNull());
    });

    it('reicht den Rechtsklick auf eine Zeile an das Produkt durch (#9116)', () => {
        /*
         * `MailMessageList` nimmt `onRowContextMenu` seit jeher, die Flaeche
         * gab es nicht weiter. Der Projekt-Manager haengt dort seine
         * Wiedervorlage an — und seit #7690 ist das Menue der EINZIGE Weg
         * dorthin. Beim Umstieg waere sie ohne Ersatz verschwunden.
         */
        const menue = vi.fn();

        const { container } = render(
            <MailboxFlaeche
                seite={seite({
                    zeilen: [{ msg: { uid: 5, message_id: '<a@b>', subject: 'Angebot', from_address: 'a@b.test', from_name: 'A', date: '', has_attachments: false, attachment_count: 0, is_read: true, is_flagged: false, preview: '' }, isOutbound: false }] as never,
                    sichtbareZeilen: ((z: unknown) => z) as never,
                    /*
                     * `ticked` muss ein Set sein — die Liste fragt `.has()`.
                     * Die Attrappe trug bisher ein Array, und das fiel nie
                     * auf: Dieser Test ist der erste, der ueberhaupt eine
                     * Zeile zeichnen laesst.
                     */
                    auswahl: { ticked: new Set(), targets: [], toggle: vi.fn(), clear: vi.fn(), expanded: new Set(), toggleThread: vi.fn() } as never,
                })}
                konten={[{ id: 1, email: 'a@b.test', name: 'A' }]}
                navigieren={() => {}}
                zeilenMenue={menue}
            />,
        );

        const zeile = container.querySelector('[data-slot="mail-message-open"]');

        expect(zeile).not.toBeNull();

        // Ohne `act`: Der Rechtsklick ruft nur einen Rueckruf, er aendert in
        // dieser Flaeche keinen Zustand.
        zeile!.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true }));

        expect(menue).toHaveBeenCalled();
    });

    it('reicht die Meldung des Produkts an das Weiterleiten-Feld durch', () => {
        /*
         * `melden` kommt aus dem Haken, nicht als zweite Eigenschaft der
         * Flaeche — sonst koennten Antwort und Weiterleitung verschieden
         * melden. Hier wird genau diese Verdrahtung festgehalten.
         */
        const melden = vi.fn();
        const gesehen: Record<string, unknown>[] = [];

        const { unmount } = flaeche({
            seite: seite({
                weiterleitenOffen: true,
                melden,
                offene: { message: nachricht(), loading: false, open: vi.fn(), close: vi.fn() } as never,
            }),
            weiterleitenFeld: (eigenschaften: Record<string, unknown>) => {
                gesehen.push(eigenschaften);

                return <p>Weiterleitenfeld</p>;
            },
        });

        (gesehen[0].melden as (text: string) => void)('Weitergeleitet.');

        expect(melden).toHaveBeenCalledWith('Weitergeleitet.', true);
        unmount();
    });

    it('gibt dem Antwortfeld den Zustand des Hakens mit', () => {
        const gesehen: Record<string, unknown>[] = [];

        const { unmount } = flaeche({
            seite: seite({
                antwortOffen: true,
                antwortAnAlle: true,
                sendetAntwort: true,
                offene: { message: nachricht(), loading: false, open: vi.fn(), close: vi.fn() } as never,
            }),
            antwortFeld: (eigenschaften: Record<string, unknown>) => {
                gesehen.push(eigenschaften);

                return <p>Antwortfeld</p>;
            },
        });

        expect(gesehen[0].replyAll).toBe(true);
        expect(gesehen[0].sending).toBe(true);
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
