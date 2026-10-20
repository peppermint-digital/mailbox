import { describe, expect, it, vi } from 'vitest';
import { MailWerkzeugleiste } from '../src/MailWerkzeugleiste';
import type { MailWerkzeug } from '../src/useMailWerkzeuge';
import { render } from './support/render';

/**
 * Die eine Werkzeugleiste für alle Systeme (#8313/#8316).
 *
 * Geprüft wird vor allem das, was still falsch sein kann: Sieht eine leere
 * Leiste anders aus als eine ausgefallene? Schlägt der Zustand das Verb? Ist
 * ein Verweis-Verb ein Link und kein Knopf?
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

interface LeisteOptionen {
    werkzeuge?: MailWerkzeug[];
    unerreichbar?: string[];
    gescheitert?: string[];
    laedt?: boolean;
    leerAberVollstaendig?: boolean;
    erreicht?: boolean | null;
    ausloesen?: (w: MailWerkzeug, eingabe?: Record<string, unknown>) => Promise<{ ok: boolean; message: string }>;
    onNavigate?: (href: string) => void;
    onMeldung?: (m: string, ok: boolean) => void;
}

function leiste(optionen: LeisteOptionen = {}) {
    return render(
        <MailWerkzeugleiste
            werkzeuge={optionen.werkzeuge ?? []}
            unerreichbar={optionen.unerreichbar ?? []}
            gescheitert={optionen.gescheitert ?? []}
            laedt={optionen.laedt ?? false}
            leerAberVollstaendig={optionen.leerAberVollstaendig ?? false}
            {...{
                /*
                 * `'erreicht' in optionen` und NICHT `?? true`.
                 *
                 * `erreicht` hat drei Zustaende — true, false, null („noch
                 * nicht gefragt") —, und `null ?? true` macht aus dem dritten
                 * den ersten. Der erste Versuch dieses Helfers tat genau das,
                 * und der Test fuer „zeigt nichts, solange nicht gefragt
                 * wurde" fiel deshalb an der Huelle statt am Bauteil.
                 *
                 * Ein `??` ist immer verdaechtig, wo `null` etwas BEDEUTET.
                 */
                erreicht: 'erreicht' in optionen ? optionen.erreicht! : true,
            }}
            ausloesen={optionen.ausloesen ?? (async () => ({ ok: true, message: 'getan' }))}
            onNavigate={optionen.onNavigate}
            onMeldung={optionen.onMeldung}
        />,
    );
}

describe('„nichts zu tun" und „niemand hat geantwortet" sehen verschieden aus', () => {
    it('sagt „nichts zu tun" nur, wenn niemand fehlt', () => {
        const { container, unmount } = leiste({ leerAberVollstaendig: true });

        expect(container.textContent).toContain('nichts zu tun');
        unmount();
    });

    it('sagt es NICHT, wenn ein System nicht geantwortet hat — und nennt das System', () => {
        /*
         * Der Kern dieser Leiste. Ohne das sieht eine Mail, zu der es in der
         * Verwaltung längst ein Ticket gibt, genauso aus wie eine ohne — und
         * der Mensch davor legt das zweite an.
         */
        const { container, unmount } = leiste({ unerreichbar: ['Verwaltung'], leerAberVollstaendig: false });

        expect(container.textContent).not.toContain('nichts zu tun');
        expect(container.textContent).toContain('Verwaltung');
        expect(container.textContent).toContain('antwortet nicht');
        unmount();
    });

    it('trennt „hat nicht geantwortet" von „hat geantwortet und konnte nicht"', () => {
        // Zwei Lagen, zwei Zeilen: Bei der einen wartet man ab, bei der
        // anderen sieht jemand nach. Eine gemeinsame Warnung liesse den
        // Menschen auf etwas warten, das kaputt ist.
        const { container, unmount } = leiste({ unerreichbar: ['Verwaltung'], gescheitert: ['Ticket anlegen'] });

        expect(container.textContent).toContain('antwortet nicht');
        expect(container.textContent).toContain('konnte nicht geladen werden');
        unmount();
    });

    it('zeigt nichts, solange noch nicht gefragt wurde', () => {
        // Eine Leiste, die beim ersten Rendern „nichts zu tun" behauptet, hat
        // nichts versucht.
        const { container, unmount } = leiste({ erreicht: null, laedt: false });

        expect(container.querySelector('[data-slot="mail-werkzeugleiste"]')).toBeNull();
        unmount();
    });

    it('sagt es, wenn die Abfrage selbst nicht durchkam', () => {
        const { container, unmount } = leiste({ erreicht: false });

        expect(container.textContent).toContain('konnten nicht abgefragt werden');
        unmount();
    });
});

describe('der Zustand schlägt das Verb', () => {
    it('zeigt das vorhandene Ticket statt „Ticket anlegen"', () => {
        /*
         * Der eigentliche Gewinn: Wer eine Mail im CRM offen hat und dort
         * sieht, dass sie zu „Ticket #7" gehört, braucht die Verwaltung nicht
         * zu öffnen — und legt kein zweites an.
         */
        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ state: { label: 'Ticket #7', url: '/tickets/7' } })],
        });

        expect(container.textContent).toContain('Ticket #7');
        expect(container.textContent).not.toContain('Ticket anlegen');
        unmount();
    });

    it('macht aus dem Zustand mit Verweis einen Link dorthin', () => {
        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ state: { label: 'Ticket #7', url: '/tickets/7' } })],
        });

        expect(container.querySelector('a')?.getAttribute('href')).toBe('/tickets/7');
        unmount();
    });

    it('zeigt einen Zustand ohne Verweis als Auskunft, nicht als Link ins Leere', () => {
        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ state: { label: 'Beleg vorhanden', url: null } })],
        });

        expect(container.textContent).toContain('Beleg vorhanden');
        expect(container.querySelector('a')).toBeNull();
        unmount();
    });
});

describe('Verweis-Verb gegen Auslöse-Verb', () => {
    it('ist ein Link, wenn das Verb hinführt — und kein Knopf', () => {
        /*
         * Ein Kontakt wird aus zwölftausend gewählt, eine Aufgabe braucht
         * Projekt und Bearbeiter. Diesen Auswähler kann ein fremdes System
         * nicht zeichnen, und zu raten legt Arbeit in die falsche Schublade.
         */
        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ key: 'kontakt', label: 'Kontakt zuordnen', url: 'https://crm.test/kontakte?kette=1' })],
        });

        expect(container.querySelector('a')?.getAttribute('href')).toBe('https://crm.test/kontakte?kette=1');
        expect(container.querySelector('button')).toBeNull();
        unmount();
    });

    it('löst ein Verb ohne Verweis per Klick aus und gibt den Satz des Systems weiter', async () => {
        const ausloesen = vi.fn(async () => ({ ok: true, message: 'Ticket #7 angelegt.' }));
        const meldungen: [string, boolean][] = [];

        const { container, unmount } = leiste({
            werkzeuge: [werkzeug()],
            ausloesen,
            onMeldung: (m, ok) => meldungen.push([m, ok]),
        });

        container.querySelector('button')!.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(ausloesen).toHaveBeenCalledOnce();
        expect(meldungen[0]).toEqual(['Ticket #7 angelegt.', true]);
        unmount();
    });

    it('gibt auch die Absage weiter, statt sie zu verschlucken', async () => {
        // Ein Verb, das nicht konnte, ist eine Antwort. Sie nicht zu zeigen
        // hiesse: geklickt, nichts passiert, keine Spur.
        const meldungen: [string, boolean][] = [];

        const { container, unmount } = leiste({
            werkzeuge: [werkzeug()],
            ausloesen: async () => ({ ok: false, message: 'Dazu fehlt das Belegdatum.' }),
            onMeldung: (m, ok) => meldungen.push([m, ok]),
        });

        container.querySelector('button')!.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(meldungen[0]).toEqual(['Dazu fehlt das Belegdatum.', false]);
        unmount();
    });

    it('fragt nach, wenn das Verb eine Rückfrage verlangt — und tut bei Nein nichts', async () => {
        const ausloesen = vi.fn(async () => ({ ok: true, message: 'getan' }));
        const alt = globalThis.confirm;
        globalThis.confirm = () => false;

        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ confirm: 'Wirklich einen Beleg anlegen?' })],
            ausloesen,
        });

        container.querySelector('button')!.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(ausloesen).not.toHaveBeenCalled();
        globalThis.confirm = alt;
        unmount();
    });
});

describe('das fremde System ist am Verb erkennbar', () => {
    it('nennt es, wenn das Verb aus einem anderen System kommt', () => {
        /*
         * „Aufgabe anhängen" gibt es in zwei Systemen. Ohne den Namen am Verb
         * weiss niemand, in welchem die Aufgabe landet.
         */
        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ key: 'aufgabe', label: 'Aufgabe anhängen', system: 'manager', system_label: 'Projekt-Manager' })],
        });

        expect(container.textContent).toContain('Projekt-Manager');
        unmount();
    });

    it('nennt nichts, wenn das Verb aus diesem System kommt', () => {
        // Ein Abzeichen „dieses System" an jedem eigenen Verb wäre Lärm.
        const { container, unmount } = leiste({ werkzeuge: [werkzeug()] });

        expect(container.textContent).toContain('Ticket anlegen');
        expect(container.querySelector('[data-slot="badge"]')).toBeNull();
        unmount();
    });

    it('gibt einem unbekannten Symbol trotzdem ein Bild', () => {
        // Ein Produkt, das ein neues Verb anmeldet, soll keinen leeren Platz
        // bekommen, nur weil die Oberfläche sein Symbol noch nicht kennt.
        const { container, unmount } = leiste({ werkzeuge: [werkzeug({ icon: 'gibt-es-nicht' })] });

        expect(container.querySelector('button svg')).not.toBeNull();
        unmount();
    });
});

describe('ein Produkt, das sein eigenes Verb schon selbst zeichnet', () => {
    it('blendet es aus, zeigt die fremden aber weiter', () => {
        /*
         * Das CRM hat eine eigene Kontaktleiste mit Suche, Verknuepfen und
         * Loesen — 265 Zeilen. Das Verb `kontakt` fuehrt dagegen nur ins CRM.
         * Beides nebeneinander waere dieselbe Sache zweimal, einmal
         * schlechter.
         *
         * Die fremden Verben muessen bleiben: Sie sind der ganze Grund, warum
         * die Leiste dort hinkommt.
         */
        const { container, unmount } = render(
            <MailWerkzeugleiste
                werkzeuge={[
                    werkzeug({ key: 'kontakt', label: 'Kontakt zuordnen', url: '/contacts' }),
                    werkzeug({ key: 'ticket', label: 'Ticket anlegen', system: 'verwaltung', system_label: 'Verwaltung' }),
                ]}
                unerreichbar={[]}
                gescheitert={[]}
                laedt={false}
                leerAberVollstaendig={false}
                erreicht={true}
                ausloesen={async () => ({ ok: true, message: 'getan' })}
                verbergen={(w) => w.system === null}
            />,
        );

        expect(container.textContent).not.toContain('Kontakt zuordnen');
        expect(container.textContent).toContain('Ticket anlegen');
        unmount();
    });

    it('sagt NICHT „nichts zu tun", wenn nur das eigene Verb ausgeblendet ist', () => {
        /*
         * Der Fallstrick an dieser Einstellung: Wird `leer` aus der SICHTBAREN
         * Zahl berechnet, behauptet die Leiste „fuer diese Nachricht ist
         * nichts zu tun", waehrend das Verb direkt darueber in der eigenen
         * Leiste des Produkts steht. Der Satz gilt nur, wenn der SERVER nichts
         * hatte.
         */
        const { container, unmount } = render(
            <MailWerkzeugleiste
                werkzeuge={[werkzeug({ key: 'kontakt', url: '/contacts' })]}
                unerreichbar={[]}
                gescheitert={[]}
                laedt={false}
                leerAberVollstaendig={true}
                erreicht={true}
                ausloesen={async () => ({ ok: true, message: 'getan' })}
                verbergen={() => true}
            />,
        );

        expect(container.textContent).not.toContain('nichts zu tun');
        unmount();
    });
});

describe('Felder — die Zusage, hinter der bis zum 20.10.2026 nichts stand', () => {
    /**
     * `fields` reiste seit dem ersten Tag in der Drahtform mit und wurde von
     * dieser Leiste NICHT gezeichnet. Aufgefallen ist es erst, als „Zuständig"
     * als erstes Verb eine Pflichtangabe brauchte — vorher waren alle drei
     * Verben Verweis-Verben.
     *
     * Ein Werkzeug mit Pflichtfeld wäre also ohne Eingaben ausgelöst worden,
     * und der Server hätte „es wurde niemand ausgewählt" geantwortet. Ein
     * Knopf, der zuverlässig scheitert.
     */

    function mitAuswahl(teil: Partial<MailWerkzeug> = {}): MailWerkzeug {
        return werkzeug({
            key: 'person',
            label: 'Zuständig',
            icon: 'user-check',
            fields: [
                {
                    name: 'person',
                    label: 'Person',
                    type: 'select',
                    required: true,
                    options: [
                        { value: 'anna@example.test', label: 'Anna Meier' },
                        { value: 'bodo@example.test', label: 'Bodo Brecht' },
                    ],
                },
            ],
            ...teil,
        });
    }

    it('zeichnet die Auswahl und hält den Knopf aus, solange die Pflichtangabe fehlt', () => {
        const { container, unmount } = leiste({ werkzeuge: [mitAuswahl()] });

        const auswahl = container.querySelector('select');
        const knopf = container.querySelector('button');

        expect(auswahl).not.toBeNull();
        expect(auswahl?.querySelectorAll('option')).toHaveLength(3); // „bitte wählen" + zwei
        // AUS und nicht verborgen: Verborgen hiesse „das Verb gibt es hier
        // nicht", aus heisst „es fehlt noch etwas".
        expect(knopf?.disabled).toBe(true);

        unmount();
    });

    it('schickt den gewählten Wert mit — nicht nur den Schlüssel des Verbs', async () => {
        const gesehen: unknown[] = [];

        const { container, unmount } = leiste({
            werkzeuge: [mitAuswahl()],
            ausloesen: async (w, eingabe) => {
                gesehen.push([w.key, eingabe]);

                return { ok: true, message: 'Zuständig: Bodo Brecht' };
            },
        });

        const auswahl = container.querySelector('select')!;
        const knopf = container.querySelector('button')!;

        auswahl.value = 'bodo@example.test';
        auswahl.dispatchEvent(new Event('change', { bubbles: true }));

        await vi.waitFor(() => expect(knopf.disabled).toBe(false));

        knopf.click();

        await vi.waitFor(() => expect(gesehen).toHaveLength(1));

        expect(gesehen[0]).toEqual(['person', { person: 'bodo@example.test' }]);

        unmount();
    });

    it('bietet ein Verb NICHT als Knopf an, wenn es eine Angabe braucht, die hier niemand erfassen kann', () => {
        /*
         * Die dritte Lage neben „unerreichbar" und „gescheitert": Das System
         * hat geantwortet, das Verb ist in Ordnung — nur DIESE Ansicht kann
         * seine Angabe nicht erfassen. Abwarten hilft nicht, nachsehen auch
         * nicht.
         *
         * Dass daneben eine Zeile steht, ist der Punkt: Ein stilles Weglassen
         * sieht aus wie „gibt es nicht", und dann sucht jemand den Fehler bei
         * sich.
         */
        const { container, unmount } = leiste({
            werkzeuge: [
                mitAuswahl({
                    fields: [{ name: 'notiz', label: 'Notiz', type: 'freitext', required: true }],
                }),
            ],
        });

        expect(container.querySelector('button')).toBeNull();
        expect(container.textContent).toContain('nicht erfassen');

        unmount();
    });

    it('sagt bei einem unzeichenbaren Verb NICHT „nichts zu tun"', () => {
        // Die Gegenprobe. „Für diese Nachricht ist nichts zu tun" wäre hier
        // die Unwahrheit — es ist etwas zu tun, nur nicht von hier aus.
        const { container, unmount } = leiste({
            leerAberVollstaendig: true,
            werkzeuge: [
                mitAuswahl({
                    fields: [{ name: 'notiz', label: 'Notiz', type: 'freitext', required: true }],
                }),
            ],
        });

        expect(container.textContent).not.toContain('nichts zu tun');

        unmount();
    });

    it('setzt die Auswahl nach einem gelungenen Verb zurück', () => {
        /*
         * Eine stehengebliebene Auswahl liest sich wie „das ist der aktuelle
         * Stand". Der steht aber im Zustand, den der Server neu schickt — und
         * die beiden können auseinanderliegen, sobald jemand anders zuweist.
         */
        const { container, unmount } = leiste({
            werkzeuge: [mitAuswahl()],
            ausloesen: async () => ({ ok: true, message: 'getan' }),
        });

        const auswahl = container.querySelector('select')!;

        auswahl.value = 'bodo@example.test';
        auswahl.dispatchEvent(new Event('change', { bubbles: true }));

        const knopf = container.querySelector('button')!;

        return vi
            .waitFor(() => expect(knopf.disabled).toBe(false))
            .then(() => {
                knopf.click();

                return vi.waitFor(() => expect(container.querySelector('select')!.value).toBe(''));
            })
            .then(() => unmount());
    });
});

describe('Vorbelegung: den Stand zeigen UND änderbar lassen', () => {
    /**
     * Im Browser am 20.10.2026 gefunden. Der erste Entwurf von „Zuständig"
     * setzte einen ZUSTAND, und ein Zustand schlägt in der Leiste das Verb:
     * An einer zugewiesenen Mail stand nur noch ein Text, und die
     * Zuständigkeit ließ sich von dort nicht mehr ändern.
     *
     * Jetzt kommt der Stand als `value` im Feld. Beides geht: sehen und
     * ändern.
     */

    function mitVorbelegung(wert: string): MailWerkzeug {
        return werkzeug({
            key: 'person',
            label: 'Zuständig',
            fields: [
                {
                    name: 'person',
                    label: 'Person',
                    type: 'select',
                    required: true,
                    value: wert,
                    options: [
                        { value: 'anna@example.test', label: 'Anna Meier' },
                        { value: 'bodo@example.test', label: 'Bodo Brecht' },
                    ],
                },
            ],
        });
    }

    it('zeigt die vorbelegte Person und lässt den Knopf zu', () => {
        const { container, unmount } = leiste({ werkzeuge: [mitVorbelegung('bodo@example.test')] });

        expect(container.querySelector('select')?.value).toBe('bodo@example.test');
        // Nicht aus: Es fehlt nichts, und das Ändern ist hier der Normalfall.
        expect(container.querySelector('button')?.disabled).toBe(false);

        unmount();
    });

    it('schickt die vorbelegte Person mit, auch wenn niemand etwas angefasst hat', async () => {
        const gesehen: unknown[] = [];

        const { container, unmount } = leiste({
            werkzeuge: [mitVorbelegung('bodo@example.test')],
            ausloesen: async (w, eingabe) => {
                gesehen.push(eingabe);

                return { ok: true, message: 'getan' };
            },
        });

        container.querySelector('button')!.click();

        await vi.waitFor(() => expect(gesehen).toHaveLength(1));

        expect(gesehen[0]).toEqual({ person: 'bodo@example.test' });

        unmount();
    });

    it('lässt die Wahl des Menschen die Vorbelegung schlagen', async () => {
        const gesehen: unknown[] = [];

        const { container, unmount } = leiste({
            werkzeuge: [mitVorbelegung('bodo@example.test')],
            ausloesen: async (w, eingabe) => {
                gesehen.push(eingabe);

                return { ok: true, message: 'getan' };
            },
        });

        const auswahl = container.querySelector('select')!;

        auswahl.value = 'anna@example.test';
        auswahl.dispatchEvent(new Event('change', { bubbles: true }));

        await vi.waitFor(() => expect(container.querySelector('select')!.value).toBe('anna@example.test'));

        container.querySelector('button')!.click();

        await vi.waitFor(() => expect(gesehen).toHaveLength(1));

        expect(gesehen[0]).toEqual({ person: 'anna@example.test' });

        unmount();
    });

    it('hält den Knopf aus, wenn NICHTS vorbelegt ist', () => {
        // Niemand zuständig: Dann fehlt die Pflichtangabe wirklich.
        const { container, unmount } = leiste({ werkzeuge: [mitVorbelegung('')] });

        expect(container.querySelector('select')?.value).toBe('');
        expect(container.querySelector('button')?.disabled).toBe(true);

        unmount();
    });
});
