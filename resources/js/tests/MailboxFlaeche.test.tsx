import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { MailboxFlaeche } from '../src/MailboxFlaeche';
import type { MailboxSeiteErgebnis } from '../src/useMailboxSeite';
import { click, render } from './support/render';

/**
 * Die eine Fläche der Mailseite (#8377).
 *
 * Geprüft wird, was still falsch sein kann: Landen die vier Einschnitte an der
 * richtigen Stelle? Zeigt eine Seite ohne Postfach eine ERKLÄRUNG statt eines
 * leeren Browsers? Und — der Fall, der eine weiße Fläche erzeugen würde —
 * kommt die Leseansicht zurück, wenn ein Produkt kein Antwortfeld mitgibt?
 */
/**
 * Eine Nachricht, wie die Leseansicht sie braucht.
 *
 * Die kurze Fassung ({ uid, subject }) reicht NICHT: `MailMessageView` liest
 * `attachments.length`, und ein fehlendes Feld endet in
 * „Cannot read properties of undefined (reading 'length')" — ein Fehler der
 * Attrappe, der wie einer im Code aussieht.
 */
function entwurf() {
    return {
        uid: 3,
        message_id: '<entwurf@x>',
        subject: 'Halb getippt',
        from_address: 'ich@example.test',
        from_name: 'Ich',
        to: [],
        cc: [],
        date: '2026-10-29T10:00:00+01:00',
        body_html: null,
        body_text: 'Halb getippt',
        attachments: [],
        is_read: true,
        is_flagged: false,
    };
}

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
        /*
         * `targets` und `loadTargetsOnce` seit A1 (#9116): Die Flaeche zeichnet
         * das Verschieben-Menue jetzt selbst und fragt dafuer die vollstaendige
         * Ordnerliste. Die Attrappe trug beides nicht — und dann faellt sie
         * mit „Cannot read properties of undefined", nicht der Code.
         */
        ordner: { folders: [], targets: [], loading: false, loadTargetsOnce: vi.fn() },
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

    /*
     * Hier stand bis zum 30.10.2026 ein Test fuer `zeilenMenue` — einen
     * Durchgang, durch den das Produkt ein eigenes Menue haengte. Nur der
     * Manager tat das, und er haengte dort seine Wiedervorlage hinein.
     *
     * Mit A7 (#9116) bringt die Flaeche das Menue mit. CRM, Verwaltung und
     * AI Brain hatten gar keinen Weg, eine Mail aus der LISTE heraus auf
     * spaeter zu legen.
     */
    function mitZeile(nachricht: Record<string, unknown>) {
        return render(
            <MailboxFlaeche
                seite={seite({
                    zeilen: [{ msg: nachricht, isOutbound: false }] as never,
                    sichtbareZeilen: ((z: unknown) => z) as never,
                    auswahl: { ticked: new Set(), targets: [], toggle: vi.fn(), clear: vi.fn(), expanded: new Set(), toggleThread: vi.fn() } as never,
                })}
                konten={[{ id: 1, email: 'a@b.test', name: 'A' }]}
                navigieren={() => {}}
            />,
        );
    }

    const zeileRoh = {
        uid: 5,
        subject: 'Angebot',
        from_address: 'a@b.test',
        from_name: 'A',
        date: '',
        has_attachments: false,
        attachment_count: 0,
        is_read: true,
        is_flagged: false,
        preview: '',
    };

    it('oeffnet beim Rechtsklick das Wiedervorlage-Menue', async () => {
        const { container, unmount } = mitZeile({ ...zeileRoh, message_id: '<a@b>' });

        const zeile = container.querySelector('[data-slot="mail-message-open"]');
        expect(zeile).not.toBeNull();

        await act(async () => {
            zeile!.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true }));
        });

        const menue = container.querySelector('[data-slot="mailbox-zeilenmenue"]');

        expect(menue).not.toBeNull();
        expect(menue!.textContent).toContain('Heute Abend');
        expect(menue!.textContent).toContain('Wiedervorlage aufheben');
        unmount();
    });

    it('oeffnet gar kein Menue, wenn die Nachricht nicht schlummern kann', async () => {
        /*
         * Die Wiedervorlage haengt an `message_id`. Fehlt sie, waere jeder
         * Eintrag ein Knopf, der nichts tut — dann lieber kein Menue.
         */
        const { container, unmount } = mitZeile(zeileRoh);

        const zeile = container.querySelector('[data-slot="mail-message-open"]');

        await act(async () => {
            zeile!.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true }));
        });

        expect(container.querySelector('[data-slot="mailbox-zeilenmenue"]')).toBeNull();
        unmount();
    });

    it('zeigt den Weiterbearbeiten-Knopf nur an einem Entwurf (#9116)', () => {
        /*
         * Die Flaeche gab weder `isDraft` noch `onEditDraft` weiter. Folge: An
         * einem Entwurf stand gar kein Knopf — in CRM, Verwaltung und AI Brain
         * ist das heute so, und der Manager haette es beim Umstieg verloren.
         *
         * Geprueft wird der Ordner, in dem die Nachricht WIRKLICH liegt
         * (`offene.folder`), nicht der links ausgewaehlte: Ein Entwurf aus der
         * Suche bleibt einer, auch wenn daneben der Posteingang steht.
         */
        const bearbeiten = vi.fn();

        const { container } = render(
            <MailboxFlaeche
                seite={seite({
                    ordnerPfad: 'INBOX',
                    offene: {
                        message: entwurf(),
                        folder: 'Drafts',
                        loading: false,
                        open: vi.fn(),
                        close: vi.fn(),
                    } as never,
                })}
                konten={[{ id: 1, email: 'a@b.test', name: 'A' }]}
                navigieren={() => {}}
                entwurfBearbeiten={bearbeiten}
            />,
        );

        const knopf = [...container.querySelectorAll('button')].find((b) => b.textContent?.includes('Weiter bearbeiten'));

        expect(knopf).toBeDefined();

        knopf!.click();

        expect(bearbeiten).toHaveBeenCalled();
    });

    it('zeigt ihn NICHT, wenn die Nachricht im Posteingang liegt', () => {
        /*
         * Die wichtigere Richtung. Ein Knopf „Weiter bearbeiten" an einer
         * empfangenen Mail fuehrt in einen Verfasser mit fremdem Text — und
         * der Weg dorthin ist im Manager eine eigene Seite.
         */
        const { container } = render(
            <MailboxFlaeche
                seite={seite({
                    ordnerPfad: 'Drafts',
                    offene: {
                        message: entwurf(),
                        folder: 'INBOX',
                        loading: false,
                        open: vi.fn(),
                        close: vi.fn(),
                    } as never,
                })}
                konten={[{ id: 1, email: 'a@b.test', name: 'A' }]}
                navigieren={() => {}}
                entwurfBearbeiten={vi.fn()}
            />,
        );

        const knopf = [...container.querySelectorAll('button')].find((b) => b.textContent?.includes('Weiter bearbeiten'));

        expect(knopf).toBeUndefined();
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

describe('geteilte Postfaecher (A5, #9116)', () => {
    /**
     * Hier stand bis zum 30.10.2026 ein Test fuer `kontoZusatz` — einen
     * Durchgang, durch den jedes Produkt eine eigene Komponente neben den
     * Postfach-Namen haengte. Nur der Manager tat das.
     *
     * Jetzt ist es eine Eigenschaft des Kontos, und die Flaeche zeichnet. Das
     * macht den Unterschied aus, um den es geht: ob man einer Person oder
     * einem Team schreibt — in allen vier Systemen gleich sichtbar.
     */
    it('setzt den Globus neben ein geteiltes Postfach', () => {
        const { container, unmount } = flaeche({
            konten: [{ id: 1, email: 'team@example.test', name: 'Team', geteilt: true }],
        });

        expect(container.querySelector('[data-slot="mailbox-geteilt"]')).not.toBeNull();
        unmount();
    });

    it('laesst ein eigenes Postfach unmarkiert', () => {
        // Die Gegenprobe: Ein Zeichen, das ueberall steht, unterscheidet nichts.
        const { container, unmount } = flaeche({
            konten: [{ id: 1, email: 'ich@example.test', name: 'Ich' }],
        });

        expect(container.querySelector('[data-slot="mailbox-geteilt"]')).toBeNull();
        unmount();
    });
});

describe('was die Flaeche an die Liste durchreicht (#1177)', () => {
    /**
     * `useMailboxSeite` fuehrt `openedUid`, `expandedThreads` und
     * `toggleThread` laengst. Die Flaeche hat sie bis zum 30.10.2026 nicht
     * weitergereicht, und `MailMessageList` faellt ohne sie still auf
     * Vorgaben zurueck — nichts bricht, es fehlt nur.
     *
     * Gemessen vor der Behebung auf brain.proxy.peppermint-digital.com:
     * Zeile angeklickt, Nachricht offen, 0 von 25 Zeilen hervorgehoben.
     */
    function zeile(uid: number, teil: Record<string, unknown> = {}) {
        return {
            msg: {
                uid,
                message_id: `<${uid}@b>`,
                subject: `Betreff ${uid}`,
                from_address: 'a@b.test',
                from_name: 'A',
                date: '',
                has_attachments: false,
                attachment_count: 0,
                is_read: true,
                is_flagged: false,
                preview: '',
            },
            key: `k${uid}`,
            count: 1,
            isMember: false,
            isOutbound: false,
            ...teil,
        };
    }

    function mitZeilen(teil: Record<string, unknown> = {}, auswahlTeil: Record<string, unknown> = {}) {
        return render(
            <MailboxFlaeche
                seite={seite({
                    zeilen: [zeile(5), zeile(7)] as never,
                    sichtbareZeilen: ((z: unknown) => z) as never,
                    auswahl: {
                        ticked: new Set(),
                        targets: [],
                        toggle: vi.fn(),
                        clear: vi.fn(),
                        expanded: new Set(),
                        toggleThread: vi.fn(),
                        ...auswahlTeil,
                    } as never,
                    ...teil,
                })}
                konten={[{ id: 1, email: 'a@b.test', name: 'A' }]}
                navigieren={() => {}}
            />,
        );
    }

    it('markiert die Zeile der geoeffneten Nachricht', () => {
        const { container, unmount } = mitZeilen({
            offene: { message: { uid: 7, attachments: [], to: [], cc: [] }, loading: false } as never,
        });

        const zeilen = [...container.querySelectorAll('[data-slot="mail-message-row"]')];
        const markiert = zeilen.filter((e) => e.className.includes('bg-primary/10'));

        // Genau eine — nicht keine (der Fehler) und nicht alle (ein Vergleich,
        // der immer stimmt, waere genauso falsch).
        expect(markiert).toHaveLength(1);
        expect(markiert[0]!.textContent).toContain('Betreff 7');
        unmount();
    });

    it('markiert nichts, solange keine Nachricht offen ist', () => {
        const { container, unmount } = mitZeilen();

        expect([...container.querySelectorAll('[data-slot="mail-message-row"]')].filter((e) => e.className.includes('bg-primary/10'))).toHaveLength(
            0,
        );
        unmount();
    });

    it('gibt den Griff zum Aufklappen einer Kette weiter', () => {
        const umschalten = vi.fn();
        const { container, unmount } = render(
            <MailboxFlaeche
                seite={seite({
                    zeilen: [zeile(5, { count: 3 })] as never,
                    sichtbareZeilen: ((z: unknown) => z) as never,
                    auswahl: {
                        ticked: new Set(),
                        targets: [],
                        toggle: vi.fn(),
                        clear: vi.fn(),
                        expanded: new Set(),
                        toggleThread: umschalten,
                    } as never,
                })}
                konten={[{ id: 1, email: 'a@b.test', name: 'A' }]}
                navigieren={() => {}}
            />,
        );

        const pfeil = [...container.querySelectorAll('[data-slot="mail-message-row"] button[title]')].find((b) =>
            /aufklappen|zuklappen/i.test(b.getAttribute('title') ?? ''),
        );

        expect(pfeil, 'Der Aufklapp-Pfeil fehlt — ohne ihn sagt der Test nichts').not.toBeUndefined();
        click(pfeil as HTMLElement);

        // Nicht nur „wurde gerufen": Mit dem falschen Schluessel klappte eine
        // andere Kette auf.
        expect(umschalten).toHaveBeenCalledWith('k5');
        unmount();
    });
});

describe('die Durchreichen fuer den Manager (#9116)', () => {
    /**
     * Der Manager baut seine Mailseite bisher selbst zusammen und gibt dabei
     * Dinge mit, die `MailboxFlaeche` nicht kannte. Beim Umstieg waeren sie
     * still verschwunden — kein Absturz, nur ein Weniger, das erst auffaellt,
     * wenn jemand den Knopf sucht.
     *
     * Ausgezaehlt am 30.10.2026: Von den Eigenschaften, die die inneren
     * Komponenten annehmen, fehlten genau diese fuenf.
     *
     * Jeder Test prueft den INHALT, nicht nur die Anwesenheit — ein Durchgang,
     * der etwas Falsches weitergibt, waere genauso kaputt wie keiner.
     */
    it('setzt eigene Knoepfe in die Listenleiste', () => {
        const { container, unmount } = flaeche({ leisteZusatz: <button type="button">Nur meine</button> });

        expect(container.textContent).toContain('Nur meine');
        unmount();
    });

    it('zeigt den Hinweis unter der Leiste', () => {
        const { container, unmount } = flaeche({ leisteHinweis: <span>Verzeichnis von gestern</span> });

        expect(container.textContent).toContain('Verzeichnis von gestern');
        unmount();
    });

    /*
     * Hier stand bis zum 30.10.2026 ein Test fuer `ansichtZusatz` — ein
     * Durchgang fuer das Zustaendigkeits-Abzeichen. Mit A2 (#9116) zeichnet
     * die Flaeche es selbst aus `zuweisungen`, die sie ohnehin fuehrt.
     */
    it('zeigt die Zustaendigkeit neben dem Betreff, ohne Zutun des Produkts', () => {
        const { container, unmount } = flaeche({
            seite: seite({
                offene: { message: entwurf(), loading: false } as never,
                zuweisungen: {
                    users: [],
                    erreichbar: true,
                    busy: null,
                    fuer: () => ({ message_id: '<entwurf@x>', thread_id: null, user_id: 3, name: 'Chris', initials: 'C' }),
                    zuweisen: vi.fn(),
                    aufheben: vi.fn(),
                } as never,
            }),
        });

        expect(container.querySelector('[data-slot="mailbox-zustaendig"]')?.textContent).toContain('Chris');
        unmount();
    });

    it('zeigt nichts, wenn niemand zustaendig ist', () => {
        // Die Gegenprobe: Ein Abzeichen, das immer da ist, sagt nichts.
        const { container, unmount } = flaeche({
            seite: seite({ offene: { message: entwurf(), loading: false } as never }),
        });

        expect(container.querySelector('[data-slot="mailbox-zustaendig"]')).toBeNull();
        unmount();
    });

    /*
     * Hier stand bis zum 30.10.2026 ein Test fuer `verschiebenKnopf` — einen
     * Durchgang, durch den jedes Produkt seinen eigenen Knopf hereinreichte.
     * Mit A1 (#9116) zeichnet ihn die Flaeche selbst, der Durchgang ist weg.
     *
     * Der Test wandert mit statt zu verschwinden: Geprueft wird jetzt, dass
     * der Knopf OHNE Zutun des Produkts da ist.
     */
    it('zeichnet den Verschieben-Knopf selbst, ohne Zutun des Produkts', () => {
        const { container, unmount } = flaeche({
            seite: seite({ offene: { message: entwurf(), loading: false } as never }),
        });

        expect(container.querySelector('[aria-label="In Ordner verschieben"]')).not.toBeNull();
        unmount();
    });

    it('laesst die Flaeche ohne jede der fuenf unveraendert', () => {
        // Die drei anderen Produkte geben keine mit. Waere eine davon Pflicht
        // oder haette einen Rueckfall, stuende dort etwas, das niemand bestellt hat.
        const { container, unmount } = flaeche({});

        expect(container.textContent).not.toContain('Nur meine');
        expect(container.textContent).not.toContain('Verzeichnis von gestern');
        unmount();
    });
});

describe('der Gruppieren-Schalter (#1171)', () => {
    /**
     * Gemessen am 30.10.2026 live auf AI Brain, frisch geladen:
     *
     *     ungruppiert   25 Zeilen
     *     gruppiert      0 Zeilen, dazu „Keine E-Mails"
     *     wieder aus    25 Zeilen
     *
     * Dreimal in Folge im gruppierten Zustand nachgemessen, stabil 0, keine
     * Ladeplatzhalter. Es sah nicht nach einem wirkungslosen Schalter aus,
     * sondern nach einem leeren Postfach.
     *
     * Ursache: Die Vorgabe-Adresse holt in jedem Fall die flache Liste, und
     * CRM, Verwaltung und AI Brain haben gar keinen Endpunkt fuer Ketten.
     */
    function schalter(container: HTMLElement) {
        return [...container.querySelectorAll('button[aria-label]')].find((b) =>
            /gruppier/i.test(b.getAttribute('aria-label') ?? ''),
        );
    }

    it('fehlt, solange das Produkt keine Ketten laden kann', () => {
        const { container, unmount } = flaeche({});

        expect(schalter(container)).toBeUndefined();
        unmount();
    });

    it('ist da, sobald das Produkt Ketten kann', () => {
        // Die Gegenprobe gehoert dazu: Ein Test, der nur das Fehlen prueft,
        // bliebe auch dann gruen, wenn der Schalter NIE mehr erschiene.
        const { container, unmount } = flaeche({ kettenMoeglich: true });

        expect(schalter(container)).not.toBeUndefined();
        unmount();
    });
});
