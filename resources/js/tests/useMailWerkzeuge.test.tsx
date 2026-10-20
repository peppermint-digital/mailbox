import { describe, expect, it, vi } from 'vitest';
import { useState } from 'react';
import { useMailWerkzeuge, type MailWerkzeug } from '../src/useMailWerkzeuge';
import { render } from './support/render';

/**
 * Die Werkzeuge einer Mail abholen (#8313/#8316).
 *
 * ## Die Regel, um die es hier geht
 *
 * „Keine Werkzeuge" und „niemand hat geantwortet" sind zwei Antworten. Wer sie
 * zusammenwirft, zeigt dem Menschen davor ein leeres Nichts, während die
 * Verwaltung gerade nicht erreichbar ist — und er legt sein Ticket von Hand
 * ein zweites Mal an.
 *
 * Dieses Paket hat dieselbe Regel zweimal bezahlt: bei der Zuständigkeit
 * („leere Antwort ≠ leerer Bestand") und bei der Massenaktion (ein Teilerfolg,
 * der als „nicht erreichbar" ankam). Deshalb ist `leerAberVollstaendig` eine
 * eigene Auskunft und nicht `werkzeuge.length === 0`.
 */

function werkzeug(teil: Partial<MailWerkzeug> = {}): MailWerkzeug {
    return {
        key: 'ticket',
        label: 'Ticket anlegen',
        icon: 'ticket',
        order: 10,
        group: 'zuordnen',
        fields: null,
        confirm: null,
        state: null,
        url: null,
        system: null,
        system_label: null,
        ...teil,
    };
}

function antwort(body: unknown, init: { ok?: boolean; status?: number } = {}): Response {
    const { ok = true, status = 200 } = init;

    return { ok, status, json: async () => body } as unknown as Response;
}

const NACHRICHT = { message_id: '<a@example.test>', in_reply_to: null, references: null, subject: 'Angebot', from_address: 'kunde@example.test' };

interface ProbeErgebnis {
    anzahl: number;
    unerreichbar: string;
    gescheitert: string;
    leerAberVollstaendig: boolean;
    erreicht: string;
}

/** Eine Hülle, die den Haken aufruft und seine Auskunft sichtbar macht. */
function Probe({ holen, nachricht = NACHRICHT }: { holen: typeof globalThis.fetch; nachricht?: typeof NACHRICHT | null }) {
    const w = useMailWerkzeuge({
        endpoint: '/mailbox/42/tools',
        ausloesenEndpoint: '/mailbox/42/tools/run',
        nachricht,
        csrfToken: 'tok',
        fetch: holen,
    });

    return (
        <span
            data-slot="ergebnis"
            data-anzahl={w.werkzeuge.length}
            data-unerreichbar={w.unerreichbar.join(',')}
            data-gescheitert={w.gescheitert.join(',')}
            data-leer={String(w.leerAberVollstaendig)}
            data-erreicht={String(w.erreicht)}
        />
    );
}

async function probe(holen: typeof globalThis.fetch, nachricht?: typeof NACHRICHT | null): Promise<ProbeErgebnis> {
    const { container, unmount } = render(<Probe holen={holen} nachricht={nachricht} />);

    // Der Haken lädt beim Einhängen — einmal durchatmen lassen.
    await new Promise((r) => setTimeout(r, 0));
    await new Promise((r) => setTimeout(r, 0));

    const el = container.querySelector('[data-slot="ergebnis"]')!;
    const ergebnis: ProbeErgebnis = {
        anzahl: Number(el.getAttribute('data-anzahl')),
        unerreichbar: el.getAttribute('data-unerreichbar') ?? '',
        gescheitert: el.getAttribute('data-gescheitert') ?? '',
        leerAberVollstaendig: el.getAttribute('data-leer') === 'true',
        erreicht: el.getAttribute('data-erreicht') ?? '',
    };

    unmount();

    return ergebnis;
}

describe('leer ist nicht dasselbe wie ausgefallen', () => {
    it('darf „nichts zu tun" sagen, wenn wirklich niemand fehlt', async () => {
        const ergebnis = await probe(vi.fn(async () => antwort({ tools: [], unreachable: [], failed: [] })) as never);

        expect(ergebnis.anzahl).toBe(0);
        expect(ergebnis.leerAberVollstaendig).toBe(true);
    });

    it('darf es NICHT sagen, wenn ein System nicht geantwortet hat', async () => {
        /*
         * Der Kern. Ohne diese Unterscheidung sieht eine Mail, zu der es in
         * der Verwaltung längst ein Ticket gibt, genauso aus wie eine, zu der
         * es keines gibt — und der Mensch davor legt das zweite an.
         */
        const ergebnis = await probe(
            vi.fn(async () => antwort({ tools: [], unreachable: ['Verwaltung'], failed: [] })) as never,
        );

        expect(ergebnis.anzahl).toBe(0);
        expect(ergebnis.leerAberVollstaendig).toBe(false);
        expect(ergebnis.unerreichbar).toBe('Verwaltung');
    });

    it('darf es NICHT sagen, wenn ein Werkzeug gescheitert ist', async () => {
        // Andere Lage als „nicht erreichbar": Es hat geantwortet und konnte
        // nicht. Abwarten hilft hier nicht.
        const ergebnis = await probe(
            vi.fn(async () => antwort({ tools: [], unreachable: [], failed: ['Ticket anlegen'] })) as never,
        );

        expect(ergebnis.leerAberVollstaendig).toBe(false);
        expect(ergebnis.gescheitert).toBe('Ticket anlegen');
    });

    it('trennt „noch nicht gefragt" von „gefragt und nichts bekommen"', async () => {
        // Ohne Nachricht wird nicht gefragt. `erreicht: null` heisst genau
        // das — und die Leiste zeigt dann gar nichts statt einer Fehlermeldung
        // für eine Anfrage, die nie lief.
        const holen = vi.fn(async () => antwort({ tools: [], unreachable: [], failed: [] }));
        const ergebnis = await probe(holen as never, null);

        expect(ergebnis.erreicht).toBe('null');
        expect(holen).not.toHaveBeenCalled();
    });

    it('meldet eine abgelehnte Anfrage als nicht erreicht, nicht als leer', async () => {
        const ergebnis = await probe(vi.fn(async () => antwort({}, { ok: false, status: 500 })) as never);

        expect(ergebnis.erreicht).toBe('false');
        expect(ergebnis.leerAberVollstaendig).toBe(false);
    });

    it('überlebt eine Antwort, die gar kein JSON ist', async () => {
        // Ein Webserver, der seine eigene Fehlerseite schickt. Ohne den
        // try/catch wirft der Haken beim Rendern.
        const kaputt = { ok: true, status: 200, json: async () => { throw new SyntaxError('<html>'); } } as unknown as Response;
        const ergebnis = await probe(vi.fn(async () => kaputt) as never);

        expect(ergebnis.erreicht).toBe('false');
    });

    it('nimmt eine Antwort ohne die erwarteten Felder nicht fuer Inhalt', async () => {
        // Ein Produkt, das einen Schritt zurück ausgerollt ist, schickt
        // womöglich nur `{tools: ...}`. Fehlende Listen sind leer, nicht
        // undefined — sonst wirft `.join()` in der Leiste.
        const ergebnis = await probe(vi.fn(async () => antwort({ tools: [werkzeug()] })) as never);

        expect(ergebnis.anzahl).toBe(1);
        expect(ergebnis.unerreichbar).toBe('');
        expect(ergebnis.gescheitert).toBe('');
    });
});

describe('das Auslösen', () => {
    it('schickt ein Verweis-Verb nicht los', async () => {
        /*
         * Dieselbe Regel wie auf dem Server (`fuehrtHin()`), hier noch einmal:
         * Das erspart einen Netzweg für eine Antwort, die feststeht — und
         * verhindert, dass eine Oberfläche einen Auswähler übergeht, den es
         * aus gutem Grund gibt (ein Kontakt wird aus zwölftausend gewählt).
         */
        let ergebnis: { ok: boolean; message: string } | null = null;
        const holen = vi.fn(async () => antwort({ tools: [], unreachable: [], failed: [] }));

        function Ausloeser() {
            const w = useMailWerkzeuge({
                endpoint: '/mailbox/42/tools',
                ausloesenEndpoint: '/mailbox/42/tools/run',
                nachricht: NACHRICHT,
                fetch: holen as never,
            });

            return (
                <button
                    onClick={() => {
                        void w.ausloesen(werkzeug({ url: 'https://crm.test/kontakte' })).then((e) => {
                            ergebnis = e;
                        });
                    }}
                >
                    los
                </button>
            );
        }

        const { container, unmount } = render(<Ausloeser />);
        await new Promise((r) => setTimeout(r, 0));
        const vorher = holen.mock.calls.length;

        container.querySelector('button')!.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(holen.mock.calls.length).toBe(vorher);
        expect(ergebnis!.ok).toBe(false);
        unmount();
    });

    it('unterscheidet eine abgelaufene Sitzung von einem Verb, das nicht konnte', async () => {
        /*
         * Der Server antwortet auch bei einem gescheiterten Verb mit 200 und
         * `ok: false`. Ein echter 401 ist deshalb etwas anderes: die
         * Anmeldung, nicht das Verb. Beides als „ging nicht" zu zeigen hiesse,
         * jemanden die Eingabe ändern zu lassen, wo er sich neu anmelden muss.
         */
        let meldung = '';
        const holen = vi.fn(async (url: string) =>
            String(url).includes('/run')
                ? antwort({}, { ok: false, status: 401 })
                : antwort({ tools: [], unreachable: [], failed: [] }),
        );

        function Ausloeser() {
            const w = useMailWerkzeuge({
                endpoint: '/mailbox/42/tools',
                ausloesenEndpoint: '/mailbox/42/tools/run',
                nachricht: NACHRICHT,
                fetch: holen as never,
            });

            return (
                <button
                    onClick={() => {
                        void w.ausloesen(werkzeug()).then((e) => {
                            meldung = e.message;
                        });
                    }}
                >
                    los
                </button>
            );
        }

        const { container, unmount } = render(<Ausloeser />);
        await new Promise((r) => setTimeout(r, 0));
        container.querySelector('button')!.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(meldung).toContain('Sitzung');
        unmount();
    });

    it('lädt nach einem gelungenen Verb neu — sonst bietet die Leiste es zweimal an', async () => {
        const holen = vi.fn(async (url: string) =>
            String(url).includes('/run')
                ? antwort({ ok: true, message: 'Ticket #7 angelegt.', state: { label: 'Ticket #7', url: '/tickets/7' } })
                : antwort({ tools: [werkzeug()], unreachable: [], failed: [] }),
        );

        function Ausloeser() {
            const w = useMailWerkzeuge({
                endpoint: '/mailbox/42/tools',
                ausloesenEndpoint: '/mailbox/42/tools/run',
                nachricht: NACHRICHT,
                fetch: holen as never,
            });

            return <button onClick={() => void w.ausloesen(werkzeug())}>los</button>;
        }

        const { container, unmount } = render(<Ausloeser />);
        await new Promise((r) => setTimeout(r, 0));
        const listen = holen.mock.calls.filter((c) => !String(c[0]).includes('/run')).length;

        container.querySelector('button')!.click();
        await new Promise((r) => setTimeout(r, 0));
        await new Promise((r) => setTimeout(r, 0));

        const danach = holen.mock.calls.filter((c) => !String(c[0]).includes('/run')).length;

        expect(danach).toBeGreaterThan(listen);
        unmount();
    });

    it('schickt das System mit, das im Werkzeug stand', async () => {
        /*
         * „Aufgabe anhängen" gibt es in zwei Systemen. Welches gemeint war,
         * hat der Mensch beim Klick entschieden — nicht „irgendwer, der das
         * kann". Ginge das Kürzel verloren, landete die Aufgabe im falschen
         * System, und zwar ohne Fehlermeldung.
         */
        let gesendet: Record<string, unknown> = {};
        const holen = vi.fn(async (url: string, init?: RequestInit) => {
            if (String(url).includes('/run')) {
                gesendet = JSON.parse(String(init?.body)) as Record<string, unknown>;

                return antwort({ ok: true, message: 'getan', state: null });
            }

            return antwort({ tools: [], unreachable: [], failed: [] });
        });

        function Ausloeser() {
            const w = useMailWerkzeuge({
                endpoint: '/mailbox/42/tools',
                ausloesenEndpoint: '/mailbox/42/tools/run',
                nachricht: NACHRICHT,
                fetch: holen as never,
            });

            return <button onClick={() => void w.ausloesen(werkzeug({ system: 'manager', key: 'aufgabe' }))}>los</button>;
        }

        const { container, unmount } = render(<Ausloeser />);
        await new Promise((r) => setTimeout(r, 0));
        container.querySelector('button')!.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(gesendet.system).toBe('manager');
        expect(gesendet.action).toBe('aufgabe');
        expect(gesendet.message_id).toBe('<a@example.test>');
        unmount();
    });
});

describe('die Angaben, mit denen jedes System dieselbe Mail findet', () => {
    it('schickt leere Kopfzeilen NICHT mit', async () => {
        // Ein leerer `references`-Kopf ist etwas anderes als ein fehlender,
        // und der Server validiert beide verschieden.
        let gefragt = '';
        const holen = vi.fn(async (url: string) => {
            gefragt = String(url);

            return antwort({ tools: [], unreachable: [], failed: [] });
        });

        await probe(holen as never, { ...NACHRICHT, references: null, in_reply_to: null });

        expect(gefragt).toContain('message_id=');
        expect(gefragt).not.toContain('references=');
        expect(gefragt).not.toContain('in_reply_to=');
    });

    it('nimmt die Kette mit, wenn es eine gibt', async () => {
        let gefragt = '';
        const holen = vi.fn(async (url: string) => {
            gefragt = String(url);

            return antwort({ tools: [], unreachable: [], failed: [] });
        });

        await probe(holen as never, { ...NACHRICHT, in_reply_to: '<wurzel@example.test>' });

        expect(gefragt).toContain('in_reply_to=');
    });
});

describe('die veraltete Antwort', () => {
    /**
     * Wer schnell durch die Liste klickt, hat mehrere Anfragen zugleich
     * laufen — und die Antwort auf Mail 1 kann NACH der auf Mail 3
     * eintreffen. Ohne Riegel steht dann die Leiste von Mail 1 an Mail 3, und
     * ein Klick legt das Ticket an der falschen Mail an.
     *
     * Dieser Riegel stand ab dem 20.10.2026 im Haken — mit zehn Zeilen
     * Begruendung und ohne einen einzigen Test. Gefunden bei der
     * Selbstdurchsicht vor dem Ausrollen, nicht von einem Werkzeug: Ein
     * dokumentierter Riegel ohne Gegenprobe ist eine Behauptung, und davon
     * hatte dieselbe Nacht schon vier.
     */
    function langsamSchnell(): { fetch: typeof globalThis.fetch; loese: () => void } {
        const warten: (() => void)[] = [];

        const fetchImpl = (async (url: string) => {
            const welche = String(url).includes('alt') ? 'alt' : 'neu';

            if (welche === 'alt') {
                // Die ERSTE Anfrage bleibt haengen, bis wir sie freigeben.
                await new Promise<void>((r) => warten.push(r));
            }

            return {
                ok: true,
                status: 200,
                json: async () => ({
                    tools: [werkzeug({ key: welche, label: welche === 'alt' ? 'Verb der ALTEN Mail' : 'Verb der NEUEN Mail' })],
                    unreachable: [],
                    failed: [],
                }),
            } as unknown as Response;
        }) as never;

        return { fetch: fetchImpl, loese: () => warten.forEach((r) => r()) };
    }

    it('laesst die Antwort auf die VORIGE Mail die neue nicht ueberschreiben', async () => {
        const { fetch: holen, loese } = langsamSchnell();

        function Umschalter() {
            const [id, setId] = useState('<alt@example.test>');
            const w = useMailWerkzeuge({
                endpoint: '/mailbox/42/tools',
                ausloesenEndpoint: '/mailbox/42/tools/run',
                nachricht: { message_id: id, in_reply_to: null, references: null, subject: null, from_address: null },
                fetch: holen,
            });

            return (
                <div>
                    <span data-slot="erstes">{w.werkzeuge[0]?.label ?? '—'}</span>
                    <button data-slot="weiter" onClick={() => setId('<neu@example.test>')}>
                        weiter
                    </button>
                </div>
            );
        }

        const { container, unmount } = render(<Umschalter />);

        // Erste Anfrage laeuft und haengt. Jetzt auf die naechste Mail.
        await new Promise((r) => setTimeout(r, 0));
        container.querySelector<HTMLButtonElement>('[data-slot="weiter"]')!.click();
        await new Promise((r) => setTimeout(r, 0));
        await new Promise((r) => setTimeout(r, 0));

        expect(container.querySelector('[data-slot="erstes"]')!.textContent).toBe('Verb der NEUEN Mail');

        // Und jetzt kommt die alte Antwort nach. Sie darf nichts mehr aendern.
        loese();
        await new Promise((r) => setTimeout(r, 0));
        await new Promise((r) => setTimeout(r, 0));

        expect(container.querySelector('[data-slot="erstes"]')!.textContent).toBe('Verb der NEUEN Mail');
        unmount();
    });
});
