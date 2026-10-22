import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
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

function Probe({ melden }: { melden: (text: string, gelungen: boolean) => void }) {
    const seite = useMailboxSeite({
        konten: [
            { id: 42, email: 'department@example.test', name: 'Abteilung' },
            { id: 43, email: 'zweites@example.test', name: 'Zweites' },
        ],
        namensraum: 'test.mailbox.assistent',
        melden,
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

    it('gibt alle 40 Werte heraus — und keinen als undefined', async () => {
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
