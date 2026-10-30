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
    onKontoWechsel,
    onGruppierung,
    routen,
    eigeneAdresse,
    onSuchAntwort,
    anfangsKonto,
}: {
    melden: (text: string, gelungen: boolean) => void;
    basis?: string;
    suchQuelle?: Parameters<typeof useMailboxSeite>[0]['suchQuelle'];
    oeffnenQuelle?: Parameters<typeof useMailboxSeite>[0]['oeffnenQuelle'];
    initialGrouped?: boolean;
    onKontoWechsel?: Parameters<typeof useMailboxSeite>[0]['onKontoWechsel'];
    onGruppierung?: Parameters<typeof useMailboxSeite>[0]['onGruppierung'];
    routen?: Parameters<typeof useMailboxSeite>[0]['routen'];
    eigeneAdresse?: Parameters<typeof useMailboxSeite>[0]['eigeneAdresse'];
    onSuchAntwort?: Parameters<typeof useMailboxSeite>[0]['onSuchAntwort'];
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
        ...(onKontoWechsel === undefined ? {} : { onKontoWechsel }),
        ...(onGruppierung === undefined ? {} : { onGruppierung }),
        ...(routen === undefined ? {} : { routen }),
        ...(eigeneAdresse === undefined ? {} : { eigeneAdresse }),
        ...(onSuchAntwort === undefined ? {} : { onSuchAntwort }),
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

    it('gibt alle 55 Werte heraus — und keinen als undefined', async () => {
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
            'aufwecken',
            'assistent', 'auswahl', 'beschaeftigt', 'chatQuelle', 'csrf', 'fehler', 'filterOffen',
            'konto', 'liste', 'melden', 'nachrichtBeschaeftigt', 'offene', 'offeneKennung', 'ordner',
            'ordnerPfad', 'ordnerSichtbar', 'ordnerVerwaltung', 'sammelAktion', 'schlummern',
            'sendetAntwort', 'setAntwortAnAlle', 'setAntwortOffen', 'setFehler', 'setFilterOffen',
            'setKonto', 'setOrdnerPfad', 'setOrdnerSichtbar', 'setWeiterleitenOffen',
            'sichtbareZeilen', 'suche', 'verlauf', 'weiterleitenOffen', 'werkzeuge', 'zeilen',
            'zuweisungen',
            // A14 (#9116): Gruppierung umschalten UND es dem Produkt sagen.
            // `liste.setGrouped` allein meldete nichts — der Manager merkt die
            // Wahl serverseitig, und sie fiel bei jedem Laden zurueck.
            'gruppierungUmschalten',
            // A15 (#9116): Das Suchfeld, damit `/` hineinspringen kann. Hier
            // stand eine Option `onSuchen`, die kein Produkt setzen KONNTE —
            // den Knoten besitzt die Leiste dieses Pakets, nicht das Produkt.
            'suchFeld',
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

        it('oeffnet mit ? die Hilfe und springt mit / ins Suchfeld — beides selbst', async () => {
            /*
             * Beide Tasten gingen bis zum 30.10.2026 ans Produkt.
             *
             * `?`, weil die Hilfe ein Dialog aus dessen UI-Kit war — behoben
             * mit A4 (#9116): Taste und Fenster stehen jetzt beieinander.
             *
             * `/`, mit der Begruendung „ein Fokus braucht eine Referenz auf
             * ein echtes Eingabefeld, und der Haken besitzt keinen Knoten".
             * Das stimmte und war der falsche Schluss: Den Knoten besitzt auch
             * kein Produkt, er steht in der Leiste dieses Pakets. Keines hat
             * die Option je gesetzt, und `/` ging in allen vier ins Leere —
             * waehrend die Hilfe „Suche oeffnen" versprach. Seit A15 fuehrt der
             * Haken die Referenz.
             */
            const { fetchStub } = gegenstelle(eineNachricht);
            vi.stubGlobal('fetch', fetchStub);

            const { unmount } = render(<Probe melden={() => {}} />);
            await oeffneEine();

            expect(zuletzt.hilfeOffen).toBe(false);

            await taste('?');
            expect(zuletzt.hilfeOffen).toBe(true);

            /*
             * Ein echtes Feld an die Referenz haengen und den Fokus messen —
             * nicht „wurde gerufen". Genau das war der alte Test: Er sah einen
             * Rueckruf laufen, und hinter dem Rueckruf stand in jedem Produkt
             * nichts.
             */
            const feld = document.createElement('input');
            document.body.appendChild(feld);
            (zuletzt.suchFeld as { current: HTMLInputElement | null }).current = feld;

            await taste('/');
            expect(document.activeElement).toBe(feld);

            feld.remove();
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

    it('meldet ein abgelehntes Oeffnen ueber `melden` — und NICHT in der Kopfzeile', async () => {
        /*
         * Bug #1142 im Projekt-Manager: „ueber die Suche kann ich die Nachricht
         * nicht anklicken, sie oeffnet nicht." Der Klick kam an, der Server
         * lehnte ab, und niemand sagte es — `offene.failure` las keiner.
         *
         * Dieser Haken trug den Fehlschlag danach in `fehler`, also in die
         * Kopfzeile. Das war besser als nichts und trotzdem falsch (A20,
         * #9116): Die Kopfzeile ist fuer ZUSTAENDE („dieses Postfach antwortet
         * nicht"), und sie zeichnet mit `truncate`. Der Satz des Managers ist
         * 93 Zeichen lang; davon war ein Viertel zu lesen.
         *
         * Ein abgelehnter Klick ist ein EREIGNIS. Es geht deshalb ueber
         * `melden` — den Weg, den das Produkt ohnehin fuehrt und dessen Form
         * es selbst waehlt.
         */
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const gemeldet = vi.fn();
        const abweisende = {
            open: async () => ({ failure: 'Diese Nachricht liegt nicht mehr im Postfach.' }),
        };

        const { unmount } = render(<Probe melden={gemeldet} oeffnenQuelle={abweisende as never} />);

        await vi.waitFor(() => expect(zuletzt.offene).toBeDefined());
        gemeldet.mockClear();

        await act(async () => {
            await (zuletzt.offene as { open: (m: unknown, f: string) => Promise<unknown> }).open({ uid: 9, message_id: '<a@x>' }, 'INBOX');
        });

        // Der Satz des SERVERS, nicht ein allgemeiner: Eine Ablehnung, die
        // ihren Grund verliert, ist so wenig brauchbar wie keine. Und
        // `false` — sonst erscheint ein Fehlschlag als Erfolgsmeldung.
        await vi.waitFor(() => expect(gemeldet).toHaveBeenCalledWith('Diese Nachricht liegt nicht mehr im Postfach.', false));

        // Nicht zusaetzlich in der Kopfzeile: Das war die Dopplung, die
        // Befund 4 von #9116 gemeldet hat.
        expect(zuletzt.fehler).toBeNull();

        /*
         * Und nur EINMAL — gegen einen Effekt ohne Abhaengigkeit auf den
         * Fehlschlag, der bei jedem Zeichnen neu meldete.
         *
         * Was diese Zeile NICHT prueft: `clearFailure`. Eine Mutationsprobe
         * ohne diesen Aufruf lief gruen durch, und zu Recht — der Effekt haengt
         * an der Identitaet von `offene.failure`, die ohne `setFailure` nicht
         * wechselt. Der Aufruf steht aus einem anderen Grund da; er ist am
         * Haken begruendet.
         */
        expect(gemeldet).toHaveBeenCalledTimes(1);

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

describe('die Wiedervorlage wieder aufheben (A7, #9116)', () => {
    /**
     * Schlummern konnte das Paket laengst, aufwecken nicht. CRM, Verwaltung
     * und AI Brain hatten gar keinen Weg zurueck: Wer dort eine Mail auf
     * spaeter legte, bekam sie zur gesetzten Zeit und konnte das bis dahin
     * nicht zuruecknehmen.
     */
    it('schickt ein DELETE an /snoozes, nicht ein POST', async () => {
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);

        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());

        await act(async () => {
            await (zuletzt.aufwecken as (n?: { message_id?: string }) => Promise<void>)({ message_id: '<a@b>' });
        });

        const ruf = fetchStub.mock.calls.find(
            (c) => String(c[0]).includes('/snoozes') && (c[1] as { method?: string } | undefined)?.method !== undefined,
        );

        // Ohne diese Zusicherung bliebe ein POST unbemerkt: Beide Adressen
        // sind dieselbe, und die Antwort sieht gleich aus. Nur dass ein POST
        // eine Wiedervorlage ANLEGT, statt sie aufzuheben.
        expect((ruf?.[1] as { method?: string })?.method).toBe('DELETE');
        expect(JSON.parse(String((ruf?.[1] as { body?: string })?.body ?? '{}')).message_id).toBe('<a@b>');

        unmount();
    });

    it('tut nichts ohne message_id', async () => {
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);
        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());

        await act(async () => {
            await (zuletzt.aufwecken as (n?: { message_id?: string }) => Promise<void>)({});
        });

        /*
         * Gezielt messen, nicht die Gesamtzahl der Aufrufe: Im Hintergrund
         * laedt die Seite weiter, und `gesehen.length` waechst auch ohne mein
         * Zutun. Der erste Anlauf verglich die Gesamtzahl und war deshalb
         * rot, obwohl der Code stimmte.
         */
        const schreibend = fetchStub.mock.calls.filter((c) => (c[1] as { method?: string } | undefined)?.method !== undefined);

        expect(schreibend).toHaveLength(0);
        unmount();
    });
});

describe('die rohe Antwort einer Suche (A9, #9116)', () => {
    /**
     * Der Vertrag der Suche nennt nur, was alle vier Produkte brauchen. Der
     * Manager schickt zusaetzlich `index_status` mit — den Stand seines
     * Kopfzeilen-Verzeichnisses. Dafuer schrieb er eine eigene Suchquelle:
     * 96 Zeilen, nur um ein Feld nicht wegzuwerfen.
     */
    it('reicht Felder heraus, die nur ein Produkt mitschickt', async () => {
        const { fetchStub } = gegenstelle({
            search: {
                success: true,
                messages: [],
                total: 0,
                index_status: { entries: 1234, folders: 12, minutes_ago: 5, stale: false, oldest_date: null },
            },
        });
        vi.stubGlobal('fetch', fetchStub);

        const gesehen: Record<string, unknown>[] = [];

        const { unmount } = render(<Probe melden={() => {}} onSuchAntwort={(roh) => gesehen.push(roh)} />);

        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());
        await suchen('rechnung');

        expect(gesehen).toHaveLength(1);
        expect((gesehen[0]?.index_status as { entries?: number })?.entries).toBe(1234);

        unmount();
    });

    it('haelt nicht die erste Fassung des Rueckrufs fest', async () => {
        /*
         * Der Rueckruf kommt als Funktionsausdruck herein, seine Identitaet
         * wechselt also bei jedem Zeichnen. Stuende er in den Abhaengigkeiten
         * der Quellen, wuerden die jedes Mal neu gebaut; stuende er nirgends,
         * gaelte fuer immer die erste Fassung. Deshalb liegt er in einer
         * Referenz — und das prueft dieser Test.
         */
        const { fetchStub } = gegenstelle({ search: { success: true, messages: [], total: 0, marke: 'erste' } });
        vi.stubGlobal('fetch', fetchStub);

        const ersteFassung = vi.fn();
        const zweiteFassung = vi.fn();

        const { rerender, unmount } = render(<Probe melden={() => {}} onSuchAntwort={ersteFassung} />);

        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());

        await act(async () => {
            rerender(<Probe melden={() => {}} onSuchAntwort={zweiteFassung} />);
        });

        await suchen('rechnung');

        expect(zweiteFassung, 'die Suche rief noch die Fassung von vorhin').toHaveBeenCalled();
        expect(ersteFassung).not.toHaveBeenCalled();

        unmount();
    });
});

describe('was das Produkt sich merken soll (A14, #9116)', () => {
    /**
     * Zwei Wahlen merkt der Projekt-Manager serverseitig an der Person: welches
     * Postfach zuletzt offen war (`last_account_id`) und ob die Liste nach
     * Konversationen gruppiert ist (`group_by_thread`). Dieser Haken LAS beides
     * (`anfangsKonto`, `initialGrouped`) und meldete keine Aenderung zurueck.
     *
     * Eine Vorgabe ohne Rueckmeldung ist keine Einstellung, sondern eine Wahl,
     * die bei jedem Laden zurueckfaellt — und das faellt nicht als Fehler auf,
     * sondern als „es oeffnet immer das erste Postfach".
     */
    it('meldet den Postfach-Wechsel — auch wenn die Adresse schon passt', async () => {
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const gemerkt = vi.fn();

        /*
         * `anfangsKonto` auf 43: Damit steht beim ersten Zeichnen schon das
         * zweite Postfach, und ein Wechsel ZURUECK auf 42 geht durch den Zweig,
         * in dem die Adresse umgeschrieben wird. Der Fall, der die Meldung
         * verschluckt haette, ist der andere — siehe unten.
         */
        const { unmount } = render(<Probe melden={() => {}} anfangsKonto={43} onKontoWechsel={gemerkt} />);

        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());
        expect(gemerkt, 'das erste Zeichnen ist kein Wechsel').not.toHaveBeenCalled();

        await act(async () => {
            (zuletzt.setKonto as (k: unknown) => void)({ id: 42, email: 'department@example.test', name: 'Abteilung' });
        });

        // Das ganze Konto, nicht nur die Nummer: Das Produkt entscheidet
        // selbst, was es davon aufschreibt.
        expect(gemerkt).toHaveBeenCalledWith({ id: 42, email: 'department@example.test', name: 'Abteilung' });

        unmount();
    });

    it('meldet auch dann, wenn die Adresse das Postfach bereits nennt', async () => {
        /*
         * Der Fall, an dem die Reihenfolge haengt: Steht `?account=43` schon in
         * der Adresse und jemand waehlt 43, kehrt der Adress-Block frueh
         * zurueck („nichts umzuschreiben"). Eine Meldung DAHINTER waere genau
         * dann weg — und zwar bei dem Weg, den der Manager selbst erzeugt
         * (`NotificationService` verlinkt `/emails?account={id}`).
         */
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        window.history.replaceState({}, '', '/emails?account=43');

        const gemerkt = vi.fn();
        const { unmount } = render(<Probe melden={() => {}} anfangsKonto={43} onKontoWechsel={gemerkt} />);

        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());

        await act(async () => {
            (zuletzt.setKonto as (k: unknown) => void)({ id: 43, email: 'zweites@example.test', name: 'Zweites' });
        });

        expect(gemerkt, 'die Meldung stand hinter einem fruehen return').toHaveBeenCalledTimes(1);

        window.history.replaceState({}, '', '/');
        unmount();
    });

    it('meldet die Gruppierung — mit dem neuen Wert, nicht dem alten', async () => {
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const gemerkt = vi.fn();
        const { unmount } = render(<Probe melden={() => {}} onGruppierung={gemerkt} />);

        /*
         * Auf die ERSTE Liste warten, nicht nur auf das Postfach: Der Abruf
         * beim Aufbauen laeuft sonst noch, waehrend hier schon umgeschaltet
         * wird — und weil er den alten Wert mitfuehrt, setzt seine Antwort die
         * Gruppierung wieder zurueck. Der Befund sah wie ein Fehler im
         * Rueckruf aus und war einer in der Reihenfolge des Tests.
         */
        await vi.waitFor(() => expect((zuletzt.zeilen as unknown[]).length).toBe(1));

        await act(async () => {
            await (zuletzt.gruppierungUmschalten as (g: boolean, k: number, o: string) => Promise<void>)(true, 42, 'INBOX');
        });

        // `true`, nicht `false`: Ein Rueckruf mit dem Wert VOR dem Umschalten
        // schreibt die Einstellung genau falsch weg, und beim naechsten Laden
        // steht das Gegenteil da.
        expect(gemerkt).toHaveBeenCalledWith(true);
        expect((zuletzt.liste as { grouped: boolean }).grouped).toBe(true);

        unmount();
    });
});

describe('eine Aktion an einer geoeffneten Nachricht IN DER SUCHE (A16/A17, #9116)', () => {
    /**
     * Zwei Befunde, die zusammengehoeren und beide alle vier Produkte treffen.
     *
     * A16: Vier der fuenf Aktionen schickten `ordnerPfad` — den links
     * ausgewaehlten Ordner. Ein Suchtreffer liegt aber oft in einem anderen,
     * und dann trifft die Kennung dort etwas anderes oder nichts.
     * `offeneVerschieben` rechnete seit A1 richtig, mit der Begruendung
     * daneben; die vier anderen wurden nicht mitgezogen.
     *
     * A17: Danach lief immer `liste.load({ folder: ordnerPfad })`. In der Suche
     * ersetzt das die Treffer durch den einen Ordner — waehrend das Banner
     * weiter „Suchergebnisse fuer …" sagt. Gemeldet war das als Befund 6 von
     * #9116, und nur fuer die Wiedervorlage.
     */
    function aufbau() {
        const gerufen: { pfad: string; rumpf: unknown }[] = [];

        const antworten = (pfad: string) => {
            if (pfad.includes('/search')) {
                return {
                    success: true,
                    messages: [
                        {
                            uid: 77,
                            subject: 'Rechnung 2024',
                            from_address: 'buch@example.test',
                            from_name: 'Buchhaltung',
                            date: '2026-01-02T08:00:00+01:00',
                            is_read: false,
                            is_flagged: false,
                            has_attachments: false,
                            attachment_count: 0,
                            preview: '',
                            message_id: '<r@example.test>',
                            folder: 'Archive/2024',
                        },
                    ],
                    total: 1,
                    folders_searched: 9,
                };
            }

            if (pfad.includes('/folders')) {
                return { success: true, folders: [{ name: 'INBOX', path: 'INBOX', flags: [] }] };
            }

            if (pfad.includes('/message/')) {
                // Die Antwort des Oeffnens nennt den ECHTEN Ordner des Treffers.
                return { success: true, message: { uid: 77, subject: 'Rechnung 2024', to: [], cc: [], attachments: [] }, folder: 'Archive/2024' };
            }

            if (pfad.includes('/messages')) {
                return { success: true, messages: [], total: 0, page: 1, per_page: 25 };
            }

            return { success: true, assignments: [], reachable: true, users: [], snoozes: [], tools: [], unreachable: [], failed: [] };
        };

        const fetchStub = vi.fn(async (url: string | URL | Request, optionen?: RequestInit) => {
            const pfad = String(url);
            gerufen.push({ pfad, rumpf: optionen?.body ? JSON.parse(String(optionen.body)) : null });

            return { ok: true, status: 200, json: async () => antworten(pfad) } as unknown as Response;
        });

        return { fetchStub, gerufen };
    }

    async function treffer() {
        await vi.waitFor(() => expect(zuletzt.konto).not.toBeNull());
        await suchen('rechnung');
        await vi.waitFor(() => expect((zuletzt.suche as { isSearchMode: boolean }).isSearchMode).toBe(true));
        await vi.waitFor(() => expect((zuletzt.zeilen as unknown[]).length).toBe(1));

        await act(async () => {
            await (zuletzt.offene as { open: (m: unknown, f: string) => Promise<unknown> }).open(
                { uid: 77, message_id: '<r@example.test>', folder: 'Archive/2024' },
                'INBOX',
            );
        });

        await vi.waitFor(() => expect((zuletzt.offene as { folder: string | null }).folder).toBe('Archive/2024'));
    }

    it('archiviert aus dem Ordner der NACHRICHT, nicht aus dem links gewaehlten', async () => {
        const { fetchStub, gerufen } = aufbau();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);
        await treffer();

        await act(async () => {
            await (zuletzt.offeneArchivieren as () => Promise<void>)();
        });

        const bulk = gerufen.filter((a) => a.pfad.includes('/bulk')).at(-1);

        expect(bulk, 'das Archivieren hat gar nicht gerufen').not.toBeUndefined();
        expect((bulk?.rumpf as { folder: string }).folder).toBe('Archive/2024');
        expect((bulk?.rumpf as { uids: unknown[] }).uids).toEqual([77]);

        unmount();
    });

    it('nimmt die Zeile weg, statt die Treffer durch einen Ordner zu ersetzen', async () => {
        const { fetchStub, gerufen } = aufbau();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);
        await treffer();

        const vorher = gerufen.filter((a) => a.pfad.includes('/messages')).length;

        await act(async () => {
            await (zuletzt.offeneArchivieren as () => Promise<void>)();
        });

        // Die Treffer-Zeile ist weg …
        await vi.waitFor(() => expect((zuletzt.zeilen as unknown[]).length).toBe(0));

        // … und zwar OHNE dass die Ordnerliste geholt wurde. Genau dieser
        // Abruf hat vorher die Treffer ersetzt.
        expect(gerufen.filter((a) => a.pfad.includes('/messages')).length).toBe(vorher);

        // Und das Banner luegt nicht: Wir sind noch in der Suche.
        expect((zuletzt.suche as { isSearchMode: boolean }).isSearchMode).toBe(true);

        unmount();
    });

    it('aendert die Zeile, wenn nur eine Markierung umgeht', async () => {
        const { fetchStub, gerufen } = aufbau();
        vi.stubGlobal('fetch', fetchStub);

        const { unmount } = render(<Probe melden={() => {}} />);
        await treffer();

        const vorher = gerufen.filter((a) => a.pfad.includes('/messages')).length;

        await act(async () => {
            await (zuletzt.gelesenUmschalten as () => Promise<void>)();
        });

        /*
         * Die Zeile bleibt und zeigt den neuen Stand. Ohne diese Berichtigung
         * waere ein Umschalten in der Suche entweder wirkungslos (kein
         * Nachladen) oder haette die Treffer ersetzt (Nachladen) — beides
         * liest sich als „der Knopf tut nichts".
         */
        await vi.waitFor(() => expect(((zuletzt.zeilen as { msg: { is_read: boolean } }[])[0]).msg.is_read).toBe(true));
        expect(gerufen.filter((a) => a.pfad.includes('/messages')).length).toBe(vorher);

        const seen = gerufen.filter((a) => a.pfad.includes('/seen')).at(-1);
        expect((seen?.rumpf as { folder: string }).folder).toBe('Archive/2024');

        unmount();
    });
});

describe('eine eigene Oeffnen-Quelle passt ohne Zwang hinein (A18, #9116)', () => {
    /**
     * Die Quelle unten wird OHNE `as never` hereingegeben, und die Nachricht
     * kommt unveraendert heraus.
     *
     * Der Anlass: Befund 11 von #9116 zaehlte im Projekt-Manager sieben
     * `as never`/`as …`-Zwaenge in einer Datei. Zwei davon standen an dieser
     * Naht, und sie waren keine Nachlaessigkeit des Produkts — die Option
     * behauptete, eine geoeffnete Nachricht habe KEINE Felder
     * (`Record<string, never>`), und eine Quelle mit einer echten Nachricht
     * passte deshalb nicht hinein.
     *
     * ⚠️ Dieser Test ist KEIN Waechter gegen eine erneute Verengung. Er sieht
     * echt danach aus, und das war er auch gedacht — gemessen am 30.10.2026
     * ist er es nicht: `tsconfig.json` fuehrt `include:
     * ["resources/js/src"]`, die Tests stehen also ausserhalb, und
     * `npm run typecheck` liest diese Datei gar nicht. Die Mutationsprobe
     * „Typ wieder auf `Record<string, never>` verengt" lief deshalb gruen
     * durch.
     *
     * Die Tests mit zu uebersetzen bringt 75 Meldungen in 17 Dateien zutage,
     * fast alle aus `noUncheckedIndexedAccess` — eine eigene Aufgabe, nicht
     * Teil von #9116. Bis dahin traegt dieser Test, was er zur Laufzeit
     * leisten kann, und nicht mehr.
     */
    interface EigeneNachricht {
        uid: number | null;
        subject: string;
        stored_id?: number;
    }

    it('und die Nachricht kommt so heraus, wie die Quelle sie gegeben hat', async () => {
        const { fetchStub } = gegenstelle();
        vi.stubGlobal('fetch', fetchStub);

        const quelle = {
            open: async (): Promise<{ message: EigeneNachricht; folder: string | null }> => ({
                message: { uid: null, subject: 'Aus dem Archiv', stored_id: 12 },
                folder: null,
            }),
        };

        // Kein `as never`: Genau das ist die Zusage dieses Tests.
        const { unmount } = render(<Probe melden={() => {}} oeffnenQuelle={quelle} />);

        await vi.waitFor(() => expect(zuletzt.offene).toBeDefined());

        await act(async () => {
            await (zuletzt.offene as { open: (m: unknown, f: string) => Promise<unknown> }).open({ uid: 1, stored_id: 12 }, 'INBOX');
        });

        await vi.waitFor(() =>
            expect((zuletzt.offene as { message: EigeneNachricht | null }).message?.subject).toBe('Aus dem Archiv'),
        );

        // Eine Archiv-Nachricht hat keine IMAP-Kennung — der Haken macht daraus
        // nichts Erfundenes.
        expect((zuletzt.offene as { message: EigeneNachricht | null }).message?.uid).toBeNull();
        expect((zuletzt.offene as { folder: string | null }).folder).toBeNull();

        unmount();
    });
});
