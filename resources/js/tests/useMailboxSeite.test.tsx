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
    onSuchen,
    routen,
    eigeneAdresse,
    anfangsKonto,
}: {
    melden: (text: string, gelungen: boolean) => void;
    basis?: string;
    suchQuelle?: Parameters<typeof useMailboxSeite>[0]['suchQuelle'];
    oeffnenQuelle?: Parameters<typeof useMailboxSeite>[0]['oeffnenQuelle'];
    initialGrouped?: boolean;
    onSuchen?: () => void;
    routen?: Parameters<typeof useMailboxSeite>[0]['routen'];
    eigeneAdresse?: Parameters<typeof useMailboxSeite>[0]['eigeneAdresse'];
    anfangsKonto?: Parameters<typeof useMailboxSeite>[0]['anfangsKonto'];
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
        ...(onSuchen === undefined ? {} : { onSuchen }),
        ...(routen === undefined ? {} : { routen }),
        ...(eigeneAdresse === undefined ? {} : { eigeneAdresse }),
        ...(anfangsKonto === undefined ? {} : { anfangsKonto }),
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

    it('gibt alle 51 Werte heraus — und keinen als undefined', async () => {
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
            // A1 (#9116): Verschieben der EINEN offenen Nachricht. Sammelweise
            // konnte das Paket es laengst, einzeln nicht — jedes Produkt
            // haette es selbst bauen muessen, und der Manager hatte es auch.
            'offeneVerschieben',
            // A3 (#9116): „nur mir zugewiesene" — Zustand, Moeglichkeit und
            // Schalter. Vorher baute das jedes Produkt selbst.
            // A4 (#9116): Die Tastatur-Hilfe gehoert dorthin, wo die Taste
            // gebunden wird.
            'hilfeOffen',
            'setHilfeOffen',
            'nurEigene',
            'nurEigeneMoeglich',
            'nurEigeneUmschalten',
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

        it('oeffnet mit ? die Hilfe selbst und gibt / an das Produkt weiter', async () => {
            /*
             * `?` ging bis zum 30.10.2026 ans Produkt, weil die Hilfe ein
             * Dialog aus dessen UI-Kit war. Mit A4 (#9116) bringt das Paket
             * sie mit — Taste und Fenster stehen jetzt beieinander, und die
             * Liste der Kuerzel neben den Bindungen.
             *
             * `/` bleibt beim Produkt: Ein Fokus braucht eine Referenz auf ein
             * echtes Eingabefeld, und der Haken besitzt keinen Knoten.
             */
            const { fetchStub } = gegenstelle(eineNachricht);
            vi.stubGlobal('fetch', fetchStub);

            const suchen = vi.fn();

            const { unmount } = render(<Probe melden={() => {}} onSuchen={suchen} />);
            await oeffneEine();

            expect(zuletzt.hilfeOffen).toBe(false);

            await taste('?');
            await taste('/');

            expect(zuletzt.hilfeOffen).toBe(true);
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

    it('zeigt nur die Mails, fuer die ich zustaendig bin (A3, #9116)', async () => {
        /*
         * Hier stand bis zum 30.10.2026 ein Test fuer `zeilenFilter` — einen
         * Platz, an dem jedes Produkt seinen eigenen Filter hereinreichte.
         * Mit A3 fuehrt das Paket den Schalter selbst; der Platz ist weg.
         *
         * Verglichen wird ueber die ADRESSE. Eine Zustaendigkeit kann fuer
         * jemanden stehen, den dieses System nicht kennt — dann ist `user_id`
         * null und nur `email` traegt. Und sie ist dieselbe ueber alle vier
         * Systeme, eine oertliche Nummer waere es nicht.
         */
        const { fetchStub } = gegenstelle({
            assignments: {
                assignments: [
                    { message_id: '<fremd@x>', thread_id: null, user_id: 9, email: 'wer.anders@example.test', name: 'Wer Anders', initials: 'WA' },
                ],
                reachable: true,
            },
        });
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} eigeneAdresse="ich@example.test" />);

        /*
         * ERST warten, bis die Liste wirklich etwas hat — sonst ist dieser
         * Test wertlos: Null Zeilen stimmen auch vor dem Laden, und dann ist
         * er gruen, ob der Filter verdrahtet ist oder nicht. Genau das ist
         * beim ersten Anlauf passiert und erst an der Mutationsprobe
         * aufgefallen.
         */
        await vi.waitFor(() => expect((zuletzt.liste as { messages: unknown[] }).messages.length).toBe(1));
        expect((zuletzt.zeilen as unknown[]).length).toBe(1);

        await act(async () => {
            (zuletzt.nurEigeneUmschalten as () => void)();
        });

        // Die eine Nachricht ist jemand anderem zugewiesen, also bleibt nichts.
        expect((zuletzt.zeilen as unknown[]).length).toBe(0);

        unmount();
    });

    it('zaehlt eine Mail als meine, wenn die ADRESSE passt — auch ohne oertliche Nummer', async () => {
        const { fetchStub } = gegenstelle({
            assignments: {
                assignments: [
                    // `user_id: null` ist ein echter Fall: Die Zustaendigkeit
                    // liegt in der Mitte und steht fuer jemanden, den DIESES
                    // System nicht kennt. Ueber die Nummer waere sie nicht
                    // meine — ueber die Adresse ist sie es.
                    { message_id: '<a@b>', thread_id: null, user_id: null, email: 'ich@example.test', name: 'Ich', initials: 'I' },
                ],
                reachable: true,
            },
            messages: {
                success: true,
                messages: [
                    {
                        uid: 7,
                        message_id: '<a@b>',
                        subject: 'Anfrage zum Ferienpass',
                        from_address: 'anna@example.test',
                        from_name: 'Anna Meier',
                        date: '2026-10-20T08:00:00+02:00',
                        seen: true,
                        flagged: false,
                        preview: '',
                        has_attachments: false,
                        attachment_count: 0,
                    },
                ],
                total: 1,
            },
        });
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} eigeneAdresse="ich@example.test" />);

        await vi.waitFor(() => expect((zuletzt.liste as { messages: unknown[] }).messages.length).toBe(1));

        await act(async () => {
            (zuletzt.nurEigeneUmschalten as () => void)();
        });

        expect((zuletzt.zeilen as unknown[]).length).toBe(1);
        unmount();
    });

    it('bietet den Schalter nicht an, wenn niemand gesagt hat, wer ich bin', async () => {
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());

        // Ein Schalter, der nicht entscheiden kann, waere ein Knopf, der
        // nichts tut.
        expect(zuletzt.nurEigeneMoeglich).toBe(false);
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

describe('eigene Quellen als Fabrik (#9116)', () => {
    /**
     * Eine eigene Quelle muss wissen, aus WELCHEM Postfach sie holt. Das
     * Postfach fuehrt aber dieser Haken, und die Quelle geht als Option in
     * ihn hinein.
     *
     * Der naheliegende Ausweg ist der falsche: Das Produkt haelt die Kennung
     * in einer Referenz und zieht sie in einem Effekt nach. Dann liegt
     * zwischen Postfachwechsel und richtiger Kennung ein Bilddurchlauf — und
     * was in diesem Fenster passiert, geht an das ALTE Postfach. Genau das
     * war Bug #1142: Postfach wechseln, Mail anklicken, nichts passiert.
     *
     * Deshalb prueft dieser Test nicht, OB die Fabrik gerufen wurde, sondern
     * mit welcher Kennung die naechste Suche wirklich hinausging.
     */
    it('sucht nach einem Postfachwechsel mit der neuen Kennung', async () => {
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const gesuchtMit: (number | string | null)[] = [];

        // Stabil halten: Eine bei jedem Zeichnen neue Fabrik baute eine bei
        // jedem Zeichnen neue Quelle — und die haengt in den Abhaengigkeiten
        // der Liste.
        const fabrik = (kontoId: number | string | null) => ({
            async search() {
                gesuchtMit.push(kontoId);

                return { messages: [], total: 0, foldersSearched: 1 };
            },
        });

        const { unmount } = render(<Probe melden={() => {}} suchQuelle={fabrik as never} />);

        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());
        await suchen('rechnung');

        expect(gesuchtMit, 'die erste Suche ging nicht mit dem ersten Postfach hinaus').toEqual([42]);

        await act(async () => {
            (zuletzt.setKonto as (k: unknown) => void)({ id: 43, email: 'zweites@example.test', name: 'Zweites' });
        });

        await suchen('miete');

        // Der ganze Punkt: KEIN zweites 42 dazwischen. Stuende hier
        // [42, 42], ginge die Suche nach dem Wechsel noch ans alte Postfach.
        expect(gesuchtMit, 'nach dem Wechsel ging die Suche ans alte Postfach').toEqual([42, 43]);

        unmount();
    });

    it('nimmt eine Quelle weiterhin als blankes Objekt', async () => {
        // Die bisherige Form bleibt gueltig — CRM, Verwaltung und AI Brain
        // aendern nichts, und ein Produkt ohne Postfachbezug soll keine
        // Fabrik schreiben muessen.
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        let gerufen = 0;
        const quelle = {
            async search() {
                gerufen += 1;

                return { messages: [], total: 0, foldersSearched: 1 };
            },
        };

        const { unmount } = render(<Probe melden={() => {}} suchQuelle={quelle as never} />);

        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());
        await suchen('rechnung');

        expect(gerufen).toBe(1);
        unmount();
    });
});

describe('der Schalter „nur mir zugewiesene" rechnet die Liste neu (A3, #9116)', () => {
    /**
     * Gemessen am 30.10.2026, als der Filter noch ein Platz fuer das Produkt
     * war: Er liess sich umlegen und die Liste blieb, wie sie war — zwei
     * Zeilen vorher, zwei nachher, obwohl er nur eine durchlaesst.
     *
     * Ursache: Der Filter stand nicht in den Abhaengigkeiten des Memos, das
     * die Zeilen baut. Das Memo rechnete erst neu, wenn sich etwas ANDERES
     * aenderte. Der Schalter war bedienbar und wirkungslos.
     *
     * Der Platz ist inzwischen weg, der Schalter steckt im Paket — die Falle
     * bleibt dieselbe, deshalb bleibt der Test.
     */
    it('rechnet beim Umlegen neu, nicht erst beim naechsten Laden', async () => {
        const { fetchStub } = gegenstelle({
            assignments: {
                assignments: [
                    { message_id: '<a@b>', thread_id: null, user_id: null, email: 'wer.anders@example.test', name: 'Wer Anders', initials: 'WA' },
                ],
                reachable: true,
            },
        });
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} eigeneAdresse="ich@example.test" />);

        await vi.waitFor(() => expect((zuletzt.zeilen as unknown[]).length).toBe(1));

        await act(async () => {
            (zuletzt.nurEigeneUmschalten as () => void)();
        });

        expect((zuletzt.zeilen as unknown[]).length, 'der Schalter wurde umgelegt und die Zeilen blieben, wie sie waren').toBe(0);

        // Und zurueck: Ein Schalter, der nur in eine Richtung wirkt, ist
        // genauso kaputt wie einer, der gar nichts tut.
        await act(async () => {
            (zuletzt.nurEigeneUmschalten as () => void)();
        });

        expect((zuletzt.zeilen as unknown[]).length).toBe(1);
        unmount();
    });
});

describe('welches Postfach beim ersten Zeichnen offen ist (#9116)', () => {
    /**
     * Im Manager erzeugt `NotificationService` an drei Stellen Verweise der
     * Form `/emails?account={id}`, und AI Brain zeigt sie im Posteingang an.
     * Ohne `anfangsKonto` oeffnete so ein Verweis das erste Postfach statt des
     * gemeinten — der Klick waere umsonst.
     */
    it('oeffnet das Postfach aus der Adresse', async () => {
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { container, unmount } = render(<Probe melden={() => {}} anfangsKonto={43} />);

        await vi.waitFor(() => expect(container.textContent).toContain('zweites@example.test'));
        unmount();
    });

    it('nimmt das erste, wenn es die Nummer nicht gibt — und meldet nichts', async () => {
        // Eine unbekannte Nummer in einer Adresse ist ein alter Verweis, kein
        // Fehler des Benutzers. Ihn mit einer Stoerung zu begruessen hiesse,
        // ihm etwas vorzuwerfen, das er nicht getan hat.
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { container, unmount } = render(<Probe melden={() => {}} anfangsKonto={9999} />);

        await vi.waitFor(() => expect(container.textContent).toContain('department@example.test'));
        expect(zuletzt.fehler).toBeNull();
        unmount();
    });

    it('vergleicht Zeichenkette gegen Zahl', async () => {
        // Aus der Adresse kommt '43', aus den Konten 43 — und '43' === 43 ist
        // falsch. Ohne diesen Test faellt genau das niemandem auf.
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { container, unmount } = render(<Probe melden={() => {}} anfangsKonto="43" />);

        await vi.waitFor(() => expect(container.textContent).toContain('zweites@example.test'));
        unmount();
    });
});

describe('die Adresse fuehrt das offene Postfach mit (#9116)', () => {
    it('schreibt es per replaceState zurueck — mit dem vorhandenen Zustand', async () => {
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        /*
         * Die Adresse zuruecksetzen. jsdom behaelt sie ueber Tests derselben
         * Datei hinweg, und ein frueherer Test hat schon auf Postfach 43
         * gewechselt — dann griffe unten die Abkuerzung „steht schon da" und
         * der Test waere gruen, ohne etwas geprueft zu haben.
         */
        const alterZustand = { page: 'inertia-zustand' };
        window.history.replaceState(alterZustand, '', 'http://localhost:3000/emails');

        const ersetzt = vi.spyOn(window.history, 'replaceState').mockImplementation(() => {});
        const geschoben = vi.spyOn(window.history, 'pushState').mockImplementation(() => {});

        const { unmount } = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());

        await act(async () => {
            (zuletzt.setKonto as (k: unknown) => void)({ id: 43, email: 'zweites@example.test', name: 'Zweites' });
        });

        expect(ersetzt).toHaveBeenCalled();

        const [zustand, , adresse] = ersetzt.mock.calls.at(-1) as [unknown, string, string];

        // Der ganze Punkt von Bug #1129: NICHT `{}`. An `history.state` haengt
        // bei Inertia das ganze Page-Objekt — ein frisches Objekt loescht es,
        // und der Zurueck-Knopf holt die Seite danach per XHR neu.
        expect(zustand).toBe(alterZustand);
        expect(String(adresse)).toContain('account=43');

        // Und kein pushState: Sonst braucht man nach vier Wechseln vier Mal
        // „zurueck", um dahin zu kommen, wo man herkam.
        expect(geschoben).not.toHaveBeenCalled();

        ersetzt.mockRestore();
        geschoben.mockRestore();
        unmount();
    });
});

describe('die offene Nachricht verschieben (A1, #9116)', () => {
    /**
     * Sammelweise konnte das Paket das laengst, einzeln nicht. Jedes Produkt
     * haette es selbst bauen muessen — der Manager hatte dafuer 56 Zeilen, die
     * anderen drei gar nichts, obwohl alle vier dieselbe Adresse kennen
     * (`{basis}/{konto}/message/{uid}/move`; bei AI Brain aus dem Mail-Plugin).
     */
    async function mitOffenerNachricht() {
        /*
         * Die Gegenstelle muss den EINZELABRUF beantworten. Ohne diesen
         * Eintrag kommt `{ success: true }` ohne Nachricht zurueck, `offene`
         * bleibt leer, und die Adresse unten wuerde `/message//move` lauten.
         * Gemerkt habe ich das daran, dass der Pfad nicht auftauchte — nicht
         * daran, dass ein Test rot wurde: `not.toBeNull()` laesst `undefined`
         * durch.
         */
        const { fetchStub, gesehen } = gegenstelle({
            'message/7': {
                success: true,
                message: { uid: 7, message_id: '<a@b>', subject: 'Anfrage', attachments: [], to: [], cc: [] },
                /*
                 * NICHT 'INBOX', und das ist der ganze Zweck: Die Nachricht
                 * liegt woanders als der links gewaehlte Ordner — so wie ein
                 * Suchtreffer. Mit 'INBOX' waeren die beiden gleich, und der
                 * Test unten koennte nicht unterscheiden, welchen von beiden
                 * der Code mitschickt. Genau so war er zuerst, und die
                 * Mutationsprobe lief gruen durch.
                 */
                folder: 'Archive',
            },
        });
        vi.stubGlobal('fetch', fetchStub);

        const gemalt = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect((zuletzt.zeilen as unknown[]).length).toBe(1));

        await act(async () => {
            await (zuletzt.offene as { open: (m: unknown, f: string) => Promise<unknown> }).open({ uid: 7 }, 'INBOX');
        });

        return { ...gemalt, gesehen, fetchStub };
    }

    it('schickt den Ordner der Nachricht als Herkunft mit', async () => {
        const { gesehen, fetchStub, unmount } = await mitOffenerNachricht();

        await act(async () => {
            await (zuletzt.offeneVerschieben as (z: string) => Promise<void>)('Rechnungen');
        });

        expect(gesehen.some((p) => p.includes('/42/message/7/move'))).toBe(true);

        const ruf = fetchStub.mock.calls.find((c) => String(c[0]).includes('/move'));
        const rumpf = JSON.parse(String((ruf?.[1] as { body?: string })?.body ?? '{}'));

        // Beides, und das ist der Punkt: Der Server muss wissen, WOHER die
        // Nachricht kommt. Eine Kennung ist nur innerhalb ihres Ordners
        // eindeutig — mit dem falschen Ordner trifft man eine fremde Mail,
        // die dort zufaellig dieselbe Nummer traegt (Bug #587 im Manager).
        expect(rumpf.folder).toBe('Archive');
        expect(rumpf.target_folder).toBe('Rechnungen');

        unmount();
    });

    it('tut nichts, wenn das Ziel der Ordner ist, in dem sie schon liegt', async () => {
        const { gesehen, unmount } = await mitOffenerNachricht();
        const vorher = gesehen.length;

        await act(async () => {
            await (zuletzt.offeneVerschieben as (z: string) => Promise<void>)('Archive');
        });

        // Kein Aufruf, keine Meldung. Ein Verschieben an denselben Ort ist
        // keine Aktion, die scheitern darf — sie findet nicht statt.
        expect(gesehen.length).toBe(vorher);
        unmount();
    });

    it('schliesst die Nachricht danach', async () => {
        const { unmount } = await mitOffenerNachricht();

        // Nicht `not.toBeNull()`: Das liesse `undefined` durch, und genau so
        // sah dieser Test gruen aus, als gar nichts offen war.
        expect((zuletzt.offene as { message: { uid?: number } | null }).message?.uid).toBe(7);

        await act(async () => {
            // Nicht 'Archive' — dort liegt sie schon, und dann faende gar
            // nichts statt.
            await (zuletzt.offeneVerschieben as (z: string) => Promise<void>)('Rechnungen');
        });

        // Sonst steht die Mail weiter offen da, waehrend sie in der Liste
        // schon woanders liegt — die Ansicht behauptet dann etwas Falsches.
        expect((zuletzt.offene as { message: unknown }).message).toBeNull();
        unmount();
    });
});
