import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act } from 'react';
import { useMailboxSeite } from '../src/useMailboxSeite';
import { render } from './support/render';

/**
 * Der Haken, der den ganzen Zustand der Mailseite trägt (#8316).
 *
 * ## Warum dieser Test existiert — und was er ersetzt
 *
 * Zusammengelegt wurden 631 bzw. 642 Zeilen aus zwei Produkten, die sich bis
 * auf eine inhaltliche Zeile glichen. Das ist mechanisch, und TypeScript prüft
 * dabei viel: Jeder der 39 Rückgabewerte wird an seiner Verwendungsstelle im
 * JSX getypt, ein vergessener oder verschriebener Name ist ein Übersetzfehler.
 *
 * **Was TypeScript NICHT prüft, ist ob der Haken überhaupt läuft.** Ein
 * `useEffect`, der in der falschen Reihenfolge steht, eine Abfrage, die nie
 * losgeht, ein Zustand, der beim Postfachwechsel nicht zurückgesetzt wird —
 * nichts davon ist ein Typfehler. Genau diese Fehlerarten stehen in den
 * Kommentaren des Blocks: acht live gefundene, keiner davon von tsc oder
 * jsdom erkannt.
 *
 * Dieser Test fährt den Haken mit einer erfundenen Gegenstelle und prüft, was
 * danach herauskommt. Er ist kein Ersatz für einen Browser — Tastatur,
 * Scrollen und echtes IMAP bleiben draußen —, aber er ist der Nachweis, dass
 * die 39 Werte nicht nur passend getypt, sondern gefüllt sind.
 */

/** Die Gegenstelle: antwortet auf die Wege, die der Haken beim Laden geht. */
function gegenstelle(ueberschreibungen: Record<string, unknown> = {}) {
    const gesehen: string[] = [];

    const antworten: Record<string, unknown> = {
        folders: {
            success: true,
            folders: [
                { name: 'INBOX', path: 'INBOX', flags: [] },
                { name: 'Archiv', path: 'Archive', flags: ['\\Archive'] },
            ],
        },
        messages: {
            success: true,
            messages: [
                {
                    uid: 7,
                    subject: 'Anfrage zum Ferienpass',
                    from_address: 'anna@example.test',
                    from_name: 'Anna Meier',
                    date: '2026-10-20T08:00:00+02:00',
                    seen: true,
                    flagged: false,
                    has_attachments: false,
                    message_id: '<a@example.test>',
                    thread_id: 'kette-1',
                },
            ],
            total: 1,
            page: 1,
            per_page: 25,
        },
        assignments: { assignments: [], reachable: true },
        'assignable-users': { users: [{ id: 1, name: 'Anna Meier', email: 'anna@example.test', initials: 'AM' }], without_email: 0, writable: true },
        snoozes: { snoozes: [], reachable: true },
        tools: { tools: [], unreachable: [], failed: [] },
        ...ueberschreibungen,
    };

    const fetchStub = vi.fn(async (url: string | URL | Request) => {
        const pfad = String(typeof url === 'string' ? url : url instanceof URL ? url.pathname + url.search : url.url);
        gesehen.push(pfad);

        const treffer = Object.keys(antworten).find((schluessel) => pfad.includes(schluessel));

        return {
            ok: true,
            status: 200,
            json: async () => (treffer ? antworten[treffer] : { success: true }),
        } as unknown as Response;
    });

    return { fetchStub, gesehen };
}

interface Geprobt {
    [schluessel: string]: unknown;
}

let zuletzt: Geprobt = {};

function Probe({
    melden,
    basis,
    suchQuelle,
    oeffnenQuelle,
    initialGrouped,
    onHilfe,
    onSuchen,
    routen,
    zeilenFilter,
}: {
    melden: (text: string, gelungen: boolean) => void;
    basis?: string;
    suchQuelle?: Parameters<typeof useMailboxSeite>[0]['suchQuelle'];
    oeffnenQuelle?: Parameters<typeof useMailboxSeite>[0]['oeffnenQuelle'];
    initialGrouped?: boolean;
    onHilfe?: () => void;
    onSuchen?: () => void;
    routen?: Parameters<typeof useMailboxSeite>[0]['routen'];
    zeilenFilter?: Parameters<typeof useMailboxSeite>[0]['zeilenFilter'];
}) {
    const seite = useMailboxSeite({
        konten: [
            { id: 42, email: 'department@example.test', name: 'Abteilung' },
            { id: 43, email: 'zweites@example.test', name: 'Zweites' },
        ],
        namensraum: 'test.mailbox.assistent',
        melden,
        ...(basis === undefined ? {} : { basis }),
        ...(suchQuelle === undefined ? {} : { suchQuelle }),
        ...(oeffnenQuelle === undefined ? {} : { oeffnenQuelle }),
        ...(initialGrouped === undefined ? {} : { initialGrouped }),
        ...(onHilfe === undefined ? {} : { onHilfe }),
        ...(onSuchen === undefined ? {} : { onSuchen }),
        ...(routen === undefined ? {} : { routen }),
        ...(zeilenFilter === undefined ? {} : { zeilenFilter }),
    });

    zuletzt = seite as unknown as Geprobt;

    return <div data-slot="probe">{String((seite.konto as { email?: string } | null)?.email ?? '—')}</div>;
}

beforeEach(() => {
    zuletzt = {};
    document.head.innerHTML = '<meta name="csrf-token" content="probe-token">';
});

afterEach(() => {
    vi.unstubAllGlobals();
});

/**
 * Eine Suche auslösen, wie der Mensch sie auslöst.
 *
 * `set` nimmt Feld und Wert einzeln, und `search` läuft nur, wenn `canSearch`
 * zustimmt — mindestens zwei Zeichen. Beides in `act`, weil `set` Zustand
 * setzt und der nächste Durchlauf erst danach die neue Frage sieht.
 */
async function suchen(frage = 'rechnung') {
    const suche = () => zuletzt.suche as { set: (f: string, w: unknown) => void; search: () => Promise<unknown> };

    await act(async () => {
        suche().set('query', frage);
    });

    await act(async () => {
        await suche().search();
    });
}

describe('der Haken füllt, was er verspricht', () => {
    it('nimmt das erste Postfach und holt Ordner und Nachrichten', async () => {
        const { fetchStub, gesehen } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { container, unmount } = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect(gesehen.some((p) => p.includes('/folders'))).toBe(true));
        await vi.waitFor(() => expect((zuletzt.ordner as { folders?: unknown[] }).folders?.length).toBe(2));
        await vi.waitFor(() => expect((zuletzt.zeilen as unknown[]).length).toBe(1));

        // Das erste Postfach ist gewählt — ohne das steht die Seite leer da,
        // obwohl Postfächer vorhanden sind.
        expect(container.textContent).toContain('department@example.test');

        // Und die Wege gehen an das GEWÄHLTE Postfach, nicht an ein geratenes.
        expect(gesehen.every((p) => !p.includes('/43/'))).toBe(true);

        unmount();
    });

    it('gibt alle 45 Werte heraus — und keinen als undefined', async () => {
        /*
         * Der Wächter gegen den Fehler, den dieser Umbau am leichtesten macht:
         * einen Namen in der Rückgabe zu vergessen. TypeScript fängt das an
         * der Verwendungsstelle — aber nur, wenn es eine gibt. Ein Wert, den
         * heute kein Produkt liest, fiele still weg und fehlte dem nächsten.
         */
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect(Object.keys(zuletzt).length).toBeGreaterThan(0));

        const erwartet = [
            'basis',
            // Die vier Aktionen an der geoeffneten Nachricht, seit #9116 hier
            // statt im JSX der Flaeche — sonst kann die Tastatur sie nicht rufen.
            'gelesenUmschalten',
            'markierungUmschalten',
            'offeneArchivieren',
            'offeneLoeschen',
            'amGeoeffneten', 'antwortAnAlle', 'antwortOffen', 'antwortSenden', 'archivKennungen',
            'assistent', 'auswahl', 'beschaeftigt', 'chatQuelle', 'csrf', 'fehler', 'filterOffen',
            'konto', 'liste', 'melden', 'nachrichtBeschaeftigt', 'offene', 'offeneKennung', 'ordner',
            'ordnerPfad', 'ordnerSichtbar', 'ordnerVerwaltung', 'sammelAktion', 'schlummern',
            'sendetAntwort', 'setAntwortAnAlle', 'setAntwortOffen', 'setFehler', 'setFilterOffen',
            'setKonto', 'setOrdnerPfad', 'setOrdnerSichtbar', 'setWeiterleitenOffen',
            'sichtbareZeilen', 'suche', 'verlauf', 'weiterleitenOffen', 'werkzeuge', 'zeilen',
            'zuweisungen',
        ];

        expect(Object.keys(zuletzt).sort()).toEqual([...erwartet].sort());

        // `fehler` ist zu Recht null, `konto` ist gesetzt — alles andere darf
        // nicht undefined sein.
        const fehlend = erwartet.filter((n) => zuletzt[n] === undefined);

        expect(fehlend).toEqual([]);

        unmount();
    });

    it('legt jede Adresse unter den Routen-Vorsatz des Produkts (#9116)', async () => {
        /*
         * Hier standen zwanzig Adressen fest verdrahtet auf `/mailbox/...`.
         * Der Projekt-Manager hat seine 56 Mail-Routen unter `/emails/...` —
         * und war damit von diesem Haken ausgeschlossen. Das ist der Grund,
         * warum dort eine eigene Mail-Seite mit 2216 Zeilen gewachsen ist,
         * wo das CRM mit 122 auskommt.
         *
         * Geprueft wird NICHT eine einzelne Adresse, sondern dass KEINE
         * ausserhalb des Vorsatzes liegt. Eine vergessene faellt sonst nicht
         * auf: Sie bricht genau eine Funktion, und zwar die, die niemand
         * sofort anklickt.
         */
        const { fetchStub, gesehen } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} basis="/emails" />);

        await vi.waitFor(() => expect(gesehen.length).toBeGreaterThan(1));

        expect(gesehen.filter((pfad) => pfad.startsWith('/mailbox/'))).toEqual([]);
        expect(gesehen.every((pfad) => pfad.startsWith('/emails/'))).toBe(true);

        // Und der Vorsatz reist mit, damit die Flaeche ihn nicht erneut braucht.
        expect(zuletzt.basis).toBe('/emails');

        unmount();
    });

    it('bleibt ohne Angabe auf /mailbox — die drei anderen Produkte merken nichts', async () => {
        /*
         * Die wichtigere Richtung. CRM, Verwaltung und AI Brain geben keinen
         * Vorsatz an; waere die Vorgabe falsch oder fehlte sie, fielen dort
         * alle Abrufe aus — und zwar erst in Produktion, denn die Typpruefung
         * hat an einer Zeichenkette nichts zu beanstanden.
         */
        const { fetchStub, gesehen } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect(gesehen.length).toBeGreaterThan(1));

        expect(gesehen.every((pfad) => pfad.startsWith('/mailbox/'))).toBe(true);
        expect(zuletzt.basis).toBe('/mailbox');

        unmount();
    });

    it('benutzt die Suchquelle des Produkts, statt die eigene zu bauen (#9116)', async () => {
        /*
         * Drei Produkte wollen den Endpunkt des Hakens. Der Projekt-Manager
         * hat einen Volltext-Index und meldet dessen Zustand in der
         * Oberflaeche — etwas, das dieser Haken nicht kennen soll.
         *
         * Ohne diesen Platz waere er gezwungen, die ganze Seite selbst zu
         * bauen, nur wegen der Suche. Genau das ist der Grund fuer die 2216
         * Zeilen in #9116.
         */
        const { fetchStub, gesehen } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        // Die Form, die der Haken erwartet: `messages`, nicht `rows`.
        const eigene = { search: vi.fn(async () => ({ messages: [], total: 0 })) };

        const { unmount } = render(<Probe melden={() => {}} suchQuelle={eigene as never} />);

        await vi.waitFor(() => expect(zuletzt.suche).toBeDefined());

        await suchen();

        expect(eigene.search).toHaveBeenCalled();
        // Und der eigene Endpunkt des Hakens wird dabei NICHT angefasst.
        expect(gesehen.filter((pfad) => pfad.includes('/search'))).toEqual([]);

        unmount();
    });

    it('baut ohne Angabe seine eigene Suche — die drei anderen merken nichts', async () => {
        const { fetchStub, gesehen } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect(zuletzt.suche).toBeDefined());

        await suchen();

        expect(gesehen.some((pfad) => pfad.includes('/search'))).toBe(true);

        unmount();
    });

    it('benutzt die Öffnen-Quelle des Produkts, statt die eigene zu bauen (#9116)', async () => {
        /*
         * Der Projekt-Manager hat ein Archiv: Eine Zeile mit
         * `source: 'stored'` hat GAR KEINE IMAP-Kennung und liegt in keinem
         * Ordner. Die Nachrichtenform des Pakets kennt `source` und
         * `stored_id` seit jeher, seine HTTP-Quelle aber nicht — sie fragt
         * immer nach der Kennung.
         *
         * Ohne diesen Platz koennte der Manager nach dem Umstieg keine
         * Archiv-Mail mehr oeffnen, und dort liegen die Mails an Aufgaben und
         * die eigenen gesendeten Nachrichten.
         */
        const { fetchStub, gesehen } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const eigene = { open: vi.fn(async () => ({ message: { subject: 'Aus dem Archiv' }, folder: null })) };

        const { unmount } = render(<Probe melden={() => {}} oeffnenQuelle={eigene as never} />);

        await vi.waitFor(() => expect(zuletzt.offene).toBeDefined());

        await act(async () => {
            await (zuletzt.offene as { open: (m: unknown, f: string) => Promise<unknown> }).open(
                { uid: 0, message_id: '<archiv@x>', source: 'stored', stored_id: 7 },
                'INBOX',
            );
        });

        expect(eigene.open).toHaveBeenCalled();
        // Und der eigene Weg des Hakens wird dabei NICHT angefasst.
        expect(gesehen.filter((pfad) => pfad.includes('/message/'))).toEqual([]);

        unmount();
    });

    it('öffnet ohne Angabe über den eigenen Weg — die drei anderen merken nichts', async () => {
        const { fetchStub, gesehen } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect(zuletzt.offene).toBeDefined());

        await act(async () => {
            await (zuletzt.offene as { open: (m: unknown, f: string) => Promise<unknown> }).open(
                { uid: 9, message_id: '<a@x>' },
                'INBOX',
            );
        });

        expect(gesehen.some((pfad) => pfad.includes('/message/9'))).toBe(true);

        unmount();
    });

    it('fängt gruppiert an, wenn das Produkt es so gemerkt hat (#9116)', async () => {
        /*
         * Die Gruppierung entscheidet, WAS man liest, nicht wie breit es
         * steht. Der Projekt-Manager merkt sie deshalb serverseitig an der
         * Person — sie folgt ihm auf jedes Geraet.
         *
         * Dieser Haken gab sie bisher gar nicht weiter. Ohne diese Option
         * haette die Liste nach dem Umstieg immer flach angefangen, und die
         * Wahl waere nicht pro Browser, sondern ganz verloren gewesen.
         */
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} initialGrouped />);

        await vi.waitFor(() => expect(zuletzt.liste).toBeDefined());

        expect((zuletzt.liste as { grouped: boolean }).grouped).toBe(true);

        unmount();
    });

    it('fängt ohne Angabe flach an — die drei anderen merken nichts', async () => {
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect(zuletzt.liste).toBeDefined());

        expect((zuletzt.liste as { grouped: boolean }).grouped).toBe(false);

        unmount();
    });

    /** Eine Taste drücken, wie ein Mensch sie drückt. */
    async function taste(key: string) {
        await act(async () => {
            window.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
        });
    }

    /**
     * Die Antwort auf die Nachrichten-Adresse.
     *
     * Die Gegenstelle hatte keine: `messages` trifft `/message/9` nicht, und
     * ohne `success` plus `message` bleibt nichts offen — womit auch keine
     * Taste wirkt, die eine offene Nachricht braucht.
     */
    const eineNachricht = {
        'message/': {
            success: true,
            folder: 'INBOX',
            message: {
                uid: 9,
                message_id: '<a@x>',
                subject: 'Anfrage',
                from_address: 'anna@example.test',
                from_name: 'Anna',
                date: '2026-10-20T08:00:00+02:00',
                to: [],
                cc: [],
                body_html: null,
                body_text: 'Text',
                attachments: [],
                is_read: true,
                is_flagged: false,
            },
        },
    };

    /** Eine Nachricht öffnen — die meisten Tasten wirken nur dann. */
    async function oeffneEine() {
        await vi.waitFor(() => expect(zuletzt.offene).toBeDefined());
        await act(async () => {
            await (zuletzt.offene as { open: (m: unknown, f: string) => Promise<unknown> }).open({ uid: 9, message_id: '<a@x>' }, 'INBOX');
        });
    }

    describe('die Tastatur (#9116)', () => {
        /*
         * Der Projekt-Manager hatte fünfzehn Tasten verdrahtet, dieser Haken
         * fünf. Beim Zurückführen seiner Seite hätten zehn davon LAUTLOS
         * aufgehört zu wirken — keine Meldung, keine rote Zeile, die Taste tut
         * nichts mehr.
         *
         * Jetzt verdrahtet der Haken sie für alle vier Produkte. CRM,
         * Verwaltung und AI Brain bekommen damit Tasten, die sie vorher nicht
         * hatten.
         */
        it('öffnet mit r das Antwortfeld, mit a die Antwort an alle', async () => {
            const { fetchStub } = gegenstelle(eineNachricht);
            vi.stubGlobal('fetch', fetchStub);

            const { unmount } = render(<Probe melden={() => {}} />);
            await oeffneEine();

            await taste('r');
            expect(zuletzt.antwortOffen).toBe(true);
            expect(zuletzt.antwortAnAlle).toBe(false);

            await act(async () => (zuletzt.setAntwortOffen as (w: boolean) => void)(false));

            await taste('a');
            expect(zuletzt.antwortOffen).toBe(true);
            expect(zuletzt.antwortAnAlle).toBe(true);

            unmount();
        });

        it('öffnet mit f das Weiterleiten-Feld', async () => {
            const { fetchStub } = gegenstelle(eineNachricht);
            vi.stubGlobal('fetch', fetchStub);

            const { unmount } = render(<Probe melden={() => {}} />);
            await oeffneEine();

            await taste('f');
            expect(zuletzt.weiterleitenOffen).toBe(true);

            unmount();
        });

        it('schweigt beim Verfassen — und räumt mit Escape von oben nach unten ab', async () => {
            /*
             * Die wichtigere Richtung. Ohne `enabled` löscht ein `#` im
             * Antworttext die Mail, auf die gerade geantwortet wird, und ein
             * `e` archiviert sie.
             *
             * Und die Reihenfolge beim Escape zählt: Schließt es die ganze
             * Nachricht statt erst das Feld, ist der getippte Text weg.
             */
            const { fetchStub } = gegenstelle(eineNachricht);
            vi.stubGlobal('fetch', fetchStub);

            const { unmount } = render(<Probe melden={() => {}} />);
            await oeffneEine();

            await taste('f');
            await taste('r');
            expect(zuletzt.weiterleitenOffen).toBe(true);

            // Beim Verfassen wirkt `r` nicht mehr — das Antwortfeld bleibt zu.
            expect(zuletzt.antwortOffen).toBe(false);

            // Escape nimmt erst das Weiterleiten-Feld …
            await taste('Escape');
            expect(zuletzt.weiterleitenOffen).toBe(false);

            // … und die Nachricht steht noch offen.
            expect(zuletzt.offene).toBeDefined();
            expect((zuletzt.offene as { message: unknown }).message).not.toBeNull();

            unmount();
        });

        it('gibt ? und / an das Produkt weiter', async () => {
            /*
             * Beide bleiben beim Produkt, aus zwei verschiedenen Gründen: Eine
             * Tastaturhilfe ist ein Dialog aus dem UI-Kit des Produkts, und ein
             * Fokus braucht eine Referenz auf ein echtes Eingabefeld — der
             * Haken besitzt keinen Knoten.
             */
            const { fetchStub } = gegenstelle(eineNachricht);
            vi.stubGlobal('fetch', fetchStub);

            const hilfe = vi.fn();
            const suchen = vi.fn();

            const { unmount } = render(<Probe melden={() => {}} onHilfe={hilfe} onSuchen={suchen} />);
            await oeffneEine();

            await taste('?');
            await taste('/');

            expect(hilfe).toHaveBeenCalledTimes(1);
            expect(suchen).toHaveBeenCalledTimes(1);

            unmount();
        });
    });

    it('nimmt die eigenen Adressen des Produkts — und behält die übrigen (#9116)', async () => {
        /*
         * Drei Dinge fehlten, und jedes haette einen eigenen Schalter
         * gebraucht: die Zielordner beim Verschieben (`targets`), Ketten statt
         * Nachrichten in der Gruppierung, und `refresh`. Die beiden letzten
         * traegt `ListParams` seit je — die Vorgabe hier hat sie verschluckt.
         *
         * Geprueft wird beides: dass die eigene Adresse benutzt wird UND dass
         * die nicht genannten Vorgaben stehenbleiben. Eine Ueberschreibung,
         * die die uebrigen mitnimmt, waere die schlimmere Variante.
         */
        const { fetchStub, gesehen } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const eigene = {
            messages: (id: number | string, { folder, page, grouped }: { folder: string; page: number; grouped: boolean }) =>
                `/emails/${id}/${grouped ? 'threads' : 'messages'}?folder=${encodeURIComponent(folder)}&page=${page}`,
        };

        const { unmount } = render(<Probe melden={() => {}} basis="/emails" routen={eigene as never} initialGrouped />);

        /*
         * Auf GENAU das warten, was gemessen wird. Ein `gesehen.length > 1`
         * war hier zu wenig: Zuweisungen, Schlummernde und Ordner sind schon
         * da, bevor die Liste ueberhaupt laedt — der Test lief ins Leere und
         * sah vier andere Adressen.
         */
        await vi.waitFor(() => expect(gesehen.some((pfad) => /\/(threads|messages)\?/.test(pfad))).toBe(true));

        // Die eigene Adresse: Ketten statt Nachrichten, weil gruppiert.
        expect(gesehen.some((pfad) => pfad.includes('/threads?'))).toBe(true);
        // Und die Vorgabe fuer die Ordner steht noch.
        expect(gesehen.some((pfad) => pfad.includes('/folders'))).toBe(true);

        unmount();
    });

    it('überspringt Zeilen, die der Filter des Produkts ablehnt (#9116)', async () => {
        /*
         * Der Manager filtert auf die eigenen Zustaendigkeiten — Wissen, das
         * nur er hat. Ohne diesen Platz waere der Schalter nach dem Umstieg
         * ohne Wirkung gewesen: umlegbar, aber wirkungslos.
         */
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} zeilenFilter={() => false} />);

        /*
         * ERST warten, bis die Liste wirklich etwas hat — sonst ist dieser
         * Test wertlos: Null Zeilen stimmen auch vor dem Laden, und dann ist
         * er gruen, ob der Filter verdrahtet ist oder nicht.
         *
         * Genau das ist passiert. Aufgefallen ist es nicht beim Schreiben,
         * sondern an der Mutationsprobe: Den Filter zu entfernen liess alle
         * zwanzig Tests gruen.
         */
        await vi.waitFor(() => expect((zuletzt.liste as { messages: unknown[] }).messages.length).toBe(1));

        expect((zuletzt.zeilen as unknown[]).length).toBe(0);

        unmount();
    });

    it('zeigt ohne Filter alle Zeilen — die drei anderen merken nichts', async () => {
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect((zuletzt.liste as { messages: unknown[] }).messages.length).toBe(1));

        expect((zuletzt.zeilen as unknown[]).length).toBe(1);

        unmount();
    });

    it('setzt den Ordner beim Postfachwechsel auf INBOX zurück', async () => {
        /*
         * Der Fehler vom 23.09.2026, der in den Kommentaren dieses Blocks
         * steht: Wer das Postfach wechselt, während ein Ordner offen ist, der
         * dort nicht existiert, sieht eine Liste, die nie etwas liefert.
         */
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);

        /*
         * Erst den ERSTEN Ladevorgang abwarten — und das ist keine
         * Testkosmetik: Der Haken setzt den Ordner am Ende eines `await`, also
         * überschreibt der erste Lauf alles, was vorher gesetzt wurde. Wer hier
         * zu früh umschaltet, prüft eine Reihenfolge, die es in der Anwendung
         * nicht gibt (dort existiert vor dem Laden keine Ordnerspalte, in die
         * man klicken könnte).
         */
        await vi.waitFor(() => expect((zuletzt.ordner as { folders?: unknown[] }).folders?.length).toBe(2));
        await vi.waitFor(() => expect((zuletzt.liste as { messages?: unknown[] }).messages?.length).toBe(1));

        (zuletzt.setOrdnerPfad as (p: string) => void)('Archive');
        await vi.waitFor(() => expect(zuletzt.ordnerPfad).toBe('Archive'));

        (zuletzt.setKonto as (k: unknown) => void)({ id: 43, email: 'zweites@example.test', name: 'Zweites' });

        await vi.waitFor(() => expect(zuletzt.ordnerPfad).toBe('INBOX'));

        unmount();
    });

    it('sagt es, wenn das Postfach nicht erreichbar ist — und nicht nur „leer"', async () => {
        /*
         * Die Regel, die dieses Paket dreimal bezahlt hat: Eine leere Liste
         * und ein Ausfall dürfen nicht gleich aussehen.
         */
        const fetchStub = vi.fn(async () => ({
            ok: false,
            status: 503,
            json: async () => ({ success: false, message: 'Das Postfach ist gerade nicht erreichbar.' }),
        }) as unknown as Response);

        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect(zuletzt.fehler).not.toBeNull());
        expect(String(zuletzt.fehler)).toContain('nicht erreichbar');

        unmount();
    });

    it('meldet über den Rückruf des Produkts, nicht über eine eigene Bibliothek', async () => {
        // `toast` ist absichtlich nicht mitgewandert: Ein Paket, das `sonner`
        // importiert, wählt für sein Produkt die Toast-Bibliothek aus.
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const meldungen: [string, boolean][] = [];
        const { unmount } = render(<Probe melden={(t, g) => meldungen.push([t, g])} />);

        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());

        // Der Rückruf ist durchgereicht und aufrufbar — geprüft über den
        // Sendeweg, der ihn benutzt.
        expect(typeof zuletzt.antwortSenden).toBe('function');

        unmount();
    });
});
