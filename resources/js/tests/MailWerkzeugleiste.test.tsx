import { describe, expect, it, vi } from 'vitest';
import { MailWerkzeugleiste, type MailWerkzeugleisteProps } from '../src/MailWerkzeugleiste';
import type { MailWerkzeug } from '../src/useMailWerkzeuge';
import { click, render } from './support/render';

/*
 * Die Verben stehen im Inhalt der Leiste, nicht in ihrem Kopf.
 *
 * Bis zum 30.10.2026 stand hier schlicht `querySelector('button')` — „der
 * erste Knopf". Das war so lange dasselbe wie „das erste Verb", wie die Leiste
 * keinen anderen Knopf hatte. Mit dem Aufklapper fuer das Telefon (#9134) hat
 * sie einen, und zehn Tests prueften ploetzlich ihn.
 *
 * Gemeint war nie „der erste Knopf", sondern „das erste Verb". Das steht jetzt
 * da.
 */
const INHALT = '[data-slot="mail-werkzeugleiste-inhalt"]';
const MASKE = '[data-slot="mail-werkzeugleiste-maske"]';
const RUECKFRAGE = '[data-slot="mail-werkzeugleiste-rueckfrage"]';

/** Den Knopf eines Verbs IN DER REIHE. */
function verbknopf(container: HTMLElement, text: string): HTMLButtonElement {
    const knoepfe = [...container.querySelectorAll(`${INHALT} button`)];
    const treffer = knoepfe.find((k) => (k.textContent ?? '').includes(text));

    if (!treffer) {
        throw new Error(`Kein Knopf mit „${text}" — vorhanden: ${knoepfe.map((k) => k.textContent).join(' | ')}`);
    }

    return treffer as HTMLButtonElement;
}

/**
 * Die Maske eines Verbs aufklappen.
 *
 * Seit v0.195.0 stehen die Felder nicht mehr neben dem Knopf, sondern in
 * einer Maske unter der Reihe (#9145). Dass die Tests dafuer einen Klick mehr
 * brauchen, ist kein Umstand, sondern die Sache selbst: Ein Verb mit Feldern
 * ist in der Reihe EIN Knopf, und das muss so geprueft werden, wie es benutzt
 * wird.
 *
 * `verbknopf` findet den Knopf der REIHE und nicht den der Maske, weil der
 * erste Treffer in Dokumentreihenfolge gewinnt — die Maske steht darunter.
 */
function maskeAuf(container: HTMLElement, text: string): HTMLElement {
    click(verbknopf(container, text));

    const maske = container.querySelector(MASKE);

    if (!maske) {
        throw new Error(`„${text}" hat keine Maske aufgeklappt.`);
    }

    return maske as HTMLElement;
}

/**
 * Der ausloesende Knopf IN der Maske.
 *
 * Ueber seine Marke und nicht als „erster Knopf": Seit der Auswaehler mit
 * Suche dazugekommen ist, traegt die Maske mehr als einen — sein „Aendern"
 * steht VOR dem Ausloeser. Der erste Entwurf dieses Helfers nahm den ersten
 * Knopf, und der Test klickte dann „Aendern" und wartete auf ein Verb, das
 * niemand ausgeloest hatte.
 */
function maskenknopf(maske: HTMLElement): HTMLButtonElement {
    return maske.querySelector('[data-slot="mail-werkzeugleiste-ausloesen"]') as HTMLButtonElement;
}

/**
 * Einen Wert in ein Feld setzen und React davon erzaehlen.
 *
 * Danach gehoert ein `vi.waitFor` an die Aufrufstelle: Der Zustand ist einen
 * Takt spaeter da, und ein Test, der sofort prueft, prueft den Stand von
 * vorher.
 */
function waehle(feld: HTMLSelectElement | HTMLInputElement, wert: string): void {
    /*
     * Ueber den Setter des Prototyps und nicht ueber `feld.value = …`.
     *
     * React merkt sich an gesteuerten Feldern den letzten Wert, den es selbst
     * gesetzt hat. Eine direkte Zuweisung geht an diesem Merker vorbei, und
     * das folgende `input`-Ereignis wird dann als „kein Unterschied"
     * verworfen — bei einem `select` fiel das nicht auf (React hoert dort auf
     * `change`), beim Textfeld schon: Der Test setzte den Text, und der
     * Zustand blieb leer.
     */
    const bauart = feld instanceof HTMLSelectElement ? HTMLSelectElement : HTMLInputElement;
    const setter = Object.getOwnPropertyDescriptor(bauart.prototype, 'value')?.set;

    setter?.call(feld, wert);

    feld.dispatchEvent(new Event('change', { bubbles: true }));
    feld.dispatchEvent(new Event('input', { bubbles: true }));
}

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
    eigene?: Record<string, () => void>;
    verbergen?: (w: MailWerkzeug) => boolean;
    anhaenge?: { filename: string; size?: number | null }[];
    auswahlQuelle?: MailWerkzeugleisteProps['auswahlQuelle'];
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
            eigene={optionen.eigene}
            verbergen={optionen.verbergen}
            anhaenge={optionen.anhaenge}
            auswahlQuelle={optionen.auswahlQuelle}
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
        expect(container.querySelector(`${INHALT} button`)).toBeNull();
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

        container.querySelector(`${INHALT} button`)!.click();
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

        container.querySelector(`${INHALT} button`)!.click();
        await new Promise((r) => setTimeout(r, 0));

        expect(meldungen[0]).toEqual(['Dazu fehlt das Belegdatum.', false]);
        unmount();
    });

    it('fragt in der Leiste nach — nicht im Browser-Dialog', async () => {
        /*
         * Bis v0.194.x stand hier `globalThis.confirm`. Drei Dinge sprachen
         * dagegen, und das dritte ist der Grund fuer #9145:
         *
         *   - Der Dialog haelt den ganzen Reiter an.
         *   - Er laesst sich nicht uebersetzen und nicht gestalten.
         *   - Er ist eine Frage, die NICHT in der Leiste steht — genau das,
         *     was dieser Task abbaut.
         *
         * Der Test haengt deshalb ausdruecklich eine Attrappe an
         * `globalThis.confirm`, die beim Aufruf scheitert: Wer ihn
         * wiedereinbaut, soll es hier merken und nicht im Betrieb.
         */
        const ausloesen = vi.fn(async () => ({ ok: true, message: 'getan' }));
        const alt = globalThis.confirm;
        globalThis.confirm = () => {
            throw new Error('globalThis.confirm darf hier nicht mehr vorkommen.');
        };

        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ confirm: 'Wirklich einen Beleg anlegen?' })],
            ausloesen,
        });

        click(verbknopf(container, 'Ticket anlegen'));

        const kasten = container.querySelector(RUECKFRAGE);

        expect(kasten).not.toBeNull();
        expect(kasten!.textContent).toContain('Wirklich einen Beleg anlegen?');
        // Gefragt, nicht getan.
        expect(ausloesen).not.toHaveBeenCalled();

        globalThis.confirm = alt;
        unmount();
    });

    it('tut es nach dem Ja — und nimmt den Kasten weg', async () => {
        const ausloesen = vi.fn(async () => ({ ok: true, message: 'getan' }));

        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ confirm: 'Wirklich einen Beleg anlegen?' })],
            ausloesen,
        });

        click(verbknopf(container, 'Ticket anlegen'));
        click(container.querySelector(`${RUECKFRAGE} button`)!);

        await vi.waitFor(() => expect(ausloesen).toHaveBeenCalledTimes(1));
        await vi.waitFor(() => expect(container.querySelector(RUECKFRAGE)).toBeNull());

        unmount();
    });

    it('tut bei Abbrechen nichts und laesst den Knopf stehen', () => {
        const ausloesen = vi.fn(async () => ({ ok: true, message: 'getan' }));

        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ confirm: 'Wirklich einen Beleg anlegen?' })],
            ausloesen,
        });

        click(verbknopf(container, 'Ticket anlegen'));

        const knoepfe = container.querySelectorAll(`${RUECKFRAGE} button`);

        expect(knoepfe).toHaveLength(2); // Ja und Abbrechen

        click(knoepfe[1]);

        expect(container.querySelector(RUECKFRAGE)).toBeNull();
        expect(ausloesen).not.toHaveBeenCalled();
        // Das Verb ist noch da — abgebrochen heisst nicht weg.
        expect(verbknopf(container, 'Ticket anlegen')).not.toBeNull();

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

    it('zeichnet die Auswahl in der Maske und hält den Knopf aus, solange die Pflichtangabe fehlt', () => {
        const { container, unmount } = leiste({ werkzeuge: [mitAuswahl()] });

        // Zugeklappt ist das Feld NICHT da. Ein Verb mit Feldern ist in der
        // Reihe ein Knopf — sonst schiebt es seine Nachbarn aus der Zeile.
        expect(container.querySelector('select')).toBeNull();

        const maske = maskeAuf(container, 'Zuständig');
        const auswahl = maske.querySelector('select');

        expect(auswahl).not.toBeNull();
        expect(auswahl?.querySelectorAll('option')).toHaveLength(3); // „bitte wählen" + zwei
        // AUS und nicht verborgen: Verborgen hiesse „das Verb gibt es hier
        // nicht", aus heisst „es fehlt noch etwas".
        expect(maskenknopf(maske).disabled).toBe(true);

        unmount();
    });

    it('klappt die Maske wieder zu, wenn man dasselbe Verb nochmal anklickt', () => {
        const { container, unmount } = leiste({ werkzeuge: [mitAuswahl()] });

        maskeAuf(container, 'Zuständig');
        click(verbknopf(container, 'Zuständig'));

        expect(container.querySelector(MASKE)).toBeNull();

        unmount();
    });

    it('hat nur EINE Maske offen, auch wenn zwei Verben Felder haben', () => {
        /*
         * Zwei halbgefuellte Masken nebeneinander sind die Gelegenheit, die
         * Angabe ins falsche Verb zu tippen. Beide Verben tragen hier ein
         * Feld namens `person` — genau der Fall, in dem es keiner merkt.
         */
        const { container, unmount } = leiste({
            werkzeuge: [mitAuswahl(), mitAuswahl({ key: 'vertretung', label: 'Vertretung' })],
        });

        maskeAuf(container, 'Zuständig');
        maskeAuf(container, 'Vertretung');

        const masken = container.querySelectorAll(MASKE);

        expect(masken).toHaveLength(1);
        expect(masken[0].textContent).toContain('Vertretung');

        unmount();
    });

    it('behält das Eingetippte, wenn die Maske zu und wieder auf geht', async () => {
        /*
         * Wer die Maske zumacht, um in der Mail etwas nachzulesen, darf nicht
         * mit einem leeren Formular zurueckkommen — das ist der Moment, in
         * dem jemand aufgibt und doch ins andere System springt.
         */
        const { container, unmount } = leiste({ werkzeuge: [mitAuswahl()] });

        const maske = maskeAuf(container, 'Zuständig');
        waehle(maske.querySelector('select')!, 'bodo@example.test');

        await vi.waitFor(() => expect(container.querySelector(`${MASKE} select`)!.value).toBe('bodo@example.test'));

        click(verbknopf(container, 'Zuständig')); // zu
        const wieder = maskeAuf(container, 'Zuständig'); // auf

        expect(wieder.querySelector('select')!.value).toBe('bodo@example.test');

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

        const maske = maskeAuf(container, 'Zuständig');

        waehle(maske.querySelector('select')!, 'bodo@example.test');

        await vi.waitFor(() => expect(maskenknopf(maske).disabled).toBe(false));

        click(maskenknopf(maske));

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

        expect(container.querySelector(`${INHALT} button`)).toBeNull();
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

    it('schließt die Maske nach einem gelungenen Verb und setzt die Auswahl zurück', async () => {
        /*
         * Zwei Dinge auf einmal, weil sie dieselbe Gefahr abwenden: Eine
         * stehengebliebene Auswahl liest sich wie „das ist der aktuelle
         * Stand" (der steht aber im Zustand, den der Server neu schickt), und
         * eine offene Maske liest sich wie „noch nicht abgeschickt" — der
         * zweite Klick legt dann das Ticket ein zweites Mal an.
         */
        const { container, unmount } = leiste({
            werkzeuge: [mitAuswahl()],
            ausloesen: async () => ({ ok: true, message: 'getan' }),
        });

        const maske = maskeAuf(container, 'Zuständig');

        waehle(maske.querySelector('select')!, 'bodo@example.test');

        await vi.waitFor(() => expect(maskenknopf(maske).disabled).toBe(false));

        click(maskenknopf(maske));

        await vi.waitFor(() => expect(container.querySelector(MASKE)).toBeNull());

        expect(maskeAuf(container, 'Zuständig').querySelector('select')!.value).toBe('');

        unmount();
    });

    it('lässt die Maske nach einer Absage OFFEN — mit dem Eingetippten darin', async () => {
        /*
         * Die Gegenprobe. Wer die Maske im Fehlerfall verliert, tippt alles
         * neu — und die Absage kam oft genug von einer Angabe, die man
         * ändern wollte, nicht wegwerfen.
         */
        const { container, unmount } = leiste({
            werkzeuge: [mitAuswahl()],
            ausloesen: async () => ({ ok: false, message: 'Dafür fehlt dir die Berechtigung.' }),
        });

        const maske = maskeAuf(container, 'Zuständig');

        waehle(maske.querySelector('select')!, 'bodo@example.test');

        await vi.waitFor(() => expect(maskenknopf(maske).disabled).toBe(false));

        click(maskenknopf(maske));

        await vi.waitFor(() => expect(maskenknopf(maske).disabled).toBe(false));

        expect(container.querySelector(MASKE)).not.toBeNull();
        expect(container.querySelector(`${MASKE} select`)!.value).toBe('bodo@example.test');

        unmount();
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

        const maske = maskeAuf(container, 'Zuständig');

        expect(maske.querySelector('select')?.value).toBe('bodo@example.test');
        // Nicht aus: Es fehlt nichts, und das Ändern ist hier der Normalfall.
        expect(maskenknopf(maske).disabled).toBe(false);

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

        click(maskenknopf(maskeAuf(container, 'Zuständig')));

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

        const maske = maskeAuf(container, 'Zuständig');

        waehle(maske.querySelector('select')!, 'anna@example.test');

        await vi.waitFor(() => expect(container.querySelector(`${MASKE} select`)!.value).toBe('anna@example.test'));

        click(maskenknopf(maske));

        await vi.waitFor(() => expect(gesehen).toHaveLength(1));

        expect(gesehen[0]).toEqual({ person: 'anna@example.test' });

        unmount();
    });

    it('hält den Knopf aus, wenn NICHTS vorbelegt ist', () => {
        // Niemand zuständig: Dann fehlt die Pflichtangabe wirklich.
        const { container, unmount } = leiste({ werkzeuge: [mitVorbelegung('')] });

        const maske = maskeAuf(container, 'Zuständig');

        expect(maske.querySelector('select')?.value).toBe('');
        expect(maskenknopf(maske).disabled).toBe(true);

        unmount();
    });
});

describe('ein Verb, das das Produkt selbst bedient (#8434)', () => {
    /** Den Knopf zu einem Verb finden — die Leiste hat mehrere Knoepfe. */
    function knopfMit(container: HTMLElement, text: string): HTMLButtonElement {
        const treffer = [...container.querySelectorAll(`${INHALT} button`)].find((k) => (k.textContent ?? '').includes(text));

        if (!treffer) {
            throw new Error(`Kein Knopf mit „${text}" — vorhanden: ${[...container.querySelectorAll(`${INHALT} button`)].map((k) => k.textContent).join(' | ')}`);
        }

        return treffer as HTMLButtonElement;
    }

    it('ruft den eigenen Weg und fragt den Server NICHT', () => {
        /*
         * Der Fall: Der Manager hat für „Aufgabe anhängen" einen Dialog mit
         * Suche und Anhangwahl. Bisher blendete er das Verb deshalb aus und
         * setzte einen eigenen Knopf neben den Betreff — dieselbe Sache
         * zweimal im Bild, unter zwei Namen, und die Werkzeugleiste war nicht
         * mehr der Ort, an dem Werkzeuge stehen.
         */
        const ausloesen = vi.fn(async () => ({ ok: true, message: 'getan' }));
        const eigenerWeg = vi.fn();

        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ key: 'aufgabe', label: 'Aufgabe anhängen' })],
            ausloesen,
            eigene: { aufgabe: eigenerWeg },
        });

        knopfMit(container, 'Aufgabe anhängen').click();

        expect(eigenerWeg).toHaveBeenCalledOnce();
        expect(ausloesen).not.toHaveBeenCalled();
        unmount();
    });

    it('zeigt den Namen aus dem Verzeichnis, nicht einen eigenen', () => {
        // Damit das Verb im eigenen System genauso heisst wie bei den Nachbarn.
        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ key: 'aufgabe', label: 'Aufgabe anhängen' })],
            eigene: { aufgabe: () => {} },
        });

        expect(container.textContent).toContain('Aufgabe anhängen');
        unmount();
    });

    it('beansprucht NUR das genannte Verb', async () => {
        const ausloesen = vi.fn(async () => ({ ok: true, message: 'getan' }));
        const eigenerWeg = vi.fn();

        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ key: 'ticket', label: 'Ticket anlegen' })],
            ausloesen,
            eigene: { aufgabe: eigenerWeg },
        });

        knopfMit(container, 'Ticket anlegen').click();

        await vi.waitFor(() => expect(ausloesen).toHaveBeenCalledOnce());
        expect(eigenerWeg).not.toHaveBeenCalled();
        unmount();
    });

    it('markiert den beanspruchten Knopf nicht als laufend', () => {
        /*
         * Ein Knopf, der sich beim Öffnen eines Dialogs als „wird ausgeführt"
         * markiert und dann nie fertig wird, sieht aus wie ein Hänger.
         */
        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ key: 'aufgabe', label: 'Aufgabe anhängen' })],
            eigene: { aufgabe: () => {} },
        });

        knopfMit(container, 'Aufgabe anhängen').click();

        expect(container.textContent).not.toContain('Wird ausgeführt');
        unmount();
    });

    it('fragt den Server weiterhin, wenn das Produkt nichts beansprucht', async () => {
        // Die Gegenprobe: Ohne `eigene` bleibt alles, wie es war.
        const ausloesen = vi.fn(async () => ({ ok: true, message: 'getan' }));

        const { container, unmount } = leiste({
            werkzeuge: [werkzeug({ key: 'aufgabe', label: 'Aufgabe anhängen' })],
            ausloesen,
        });

        knopfMit(container, 'Aufgabe anhängen').click();

        await vi.waitFor(() => expect(ausloesen).toHaveBeenCalledOnce());
        unmount();
    });
});

describe('die Feldarten, die die Leiste zeichnen kann (#9145)', () => {
    /*
     * Bis v0.194.x war es genau eine: `select` mit fertiger Liste. Alles
     * andere landete in der Zeile „braucht eine Angabe, die diese Ansicht
     * nicht erfassen kann" — und daran hing der ganze Rest des Tasks: Ein
     * Verb, das seine Angabe hier nicht einsammeln kann, FUEHRT HIN, und
     * dieser Sprung ist das, was abgebaut wird.
     */

    const ANHAENGE = [
        { filename: 'Angebot_Meier.pdf', size: 188_416 },
        { filename: 'Signatur.png', size: 12_288 },
    ];

    function mitFeld(feld: Record<string, unknown>): MailWerkzeug {
        return werkzeug({ key: 'beleg', label: 'Als Eingangsrechnung', fields: [feld as never] });
    }

    it('zeichnet ein Textfeld und schickt, was darin steht', async () => {
        const gesehen: unknown[] = [];

        const { container, unmount } = leiste({
            werkzeuge: [mitFeld({ name: 'notiz', label: 'Notiz', type: 'text', required: true })],
            ausloesen: async (w, eingabe) => {
                gesehen.push(eingabe);

                return { ok: true, message: 'getan' };
            },
        });

        const maske = maskeAuf(container, 'Als Eingangsrechnung');
        const feld = maske.querySelector('input[type="text"]') as HTMLInputElement;

        expect(feld).not.toBeNull();
        expect(maskenknopf(maske).disabled).toBe(true);

        waehle(feld, 'Rechnung Oktober');

        await vi.waitFor(() => expect(maskenknopf(maske).disabled).toBe(false));

        click(maskenknopf(maske));

        await vi.waitFor(() => expect(gesehen).toHaveLength(1));
        expect(gesehen[0]).toEqual({ notiz: 'Rechnung Oktober' });

        unmount();
    });

    it('bietet die Anhänge DIESER Mail an — ohne dass das Verb sie mitschickt', async () => {
        /*
         * Der Kern der Feldart: Das Feld trägt KEINE `options`. Die Liste
         * kommt von der Fläche, weil das Paket die Anhänge schon kennt — ein
         * fremdes Verb könnte sie gar nicht liefern.
         */
        const gesehen: unknown[] = [];

        const { container, unmount } = leiste({
            werkzeuge: [mitFeld({ name: 'anhang', label: 'Anhang', type: 'anhang', required: true })],
            anhaenge: ANHAENGE,
            ausloesen: async (w, eingabe) => {
                gesehen.push(eingabe);

                return { ok: true, message: 'getan' };
            },
        });

        const maske = maskeAuf(container, 'Als Eingangsrechnung');
        const auswahl = maske.querySelector('select') as HTMLSelectElement;

        expect([...auswahl.querySelectorAll('option')].map((o) => o.value)).toEqual([
            '',
            'Angebot_Meier.pdf',
            'Signatur.png',
        ]);
        // Die Groesse steht dabei, sonst sind zwei Anhaenge mit aehnlichem
        // Namen nicht zu unterscheiden.
        expect(auswahl.textContent).toContain('184.0 KB');

        waehle(auswahl, 'Angebot_Meier.pdf');

        await vi.waitFor(() => expect(maskenknopf(maske).disabled).toBe(false));

        click(maskenknopf(maske));

        await vi.waitFor(() => expect(gesehen).toHaveLength(1));
        // Der DATEINAME, nicht der Index: Der stimmt nur, solange beide
        // Seiten dieselbe Mail in derselben Reihenfolge sehen.
        expect(gesehen[0]).toEqual({ anhang: 'Angebot_Meier.pdf' });

        unmount();
    });

    it('macht aus `multiple` eine Mehrfachauswahl und schickt eine LISTE', async () => {
        const gesehen: unknown[] = [];

        const { container, unmount } = leiste({
            werkzeuge: [mitFeld({ name: 'anhang', label: 'Anhänge', type: 'anhang', multiple: true, required: true })],
            anhaenge: ANHAENGE,
            ausloesen: async (w, eingabe) => {
                gesehen.push(eingabe);

                return { ok: true, message: 'getan' };
            },
        });

        const maske = maskeAuf(container, 'Als Eingangsrechnung');
        const haken = maske.querySelectorAll('input[type="checkbox"]');

        expect(haken).toHaveLength(2);
        expect(maske.querySelector('select')).toBeNull();
        // Leere Liste ist die fehlende Pflichtangabe — nicht `''`.
        expect(maskenknopf(maske).disabled).toBe(true);

        click(haken[0]);

        await vi.waitFor(() => expect(maskenknopf(maske).disabled).toBe(false));

        click(maskenknopf(maske));

        await vi.waitFor(() => expect(gesehen).toHaveLength(1));
        // Eine Liste, auch bei nur einem Haken. Wer beides lesen muesste,
        // bekaeme genau den Fehler, den `multiple` vermeiden soll.
        expect(gesehen[0]).toEqual({ anhang: ['Angebot_Meier.pdf'] });

        unmount();
    });

    it('sagt es, wenn die Mail keine Anhänge hat — und hält den Knopf aus', () => {
        /*
         * Ein leeres Kaestchen saehe aus wie „laedt noch". Der Knopf ist aus,
         * weil die Angabe Pflicht ist; das Verb bleibt aber stehen, denn ob es
         * hier SINNVOLL ist, entscheidet `anwendbar()` drueben und nicht diese
         * Ansicht.
         */
        const { container, unmount } = leiste({
            werkzeuge: [mitFeld({ name: 'anhang', label: 'Anhang', type: 'anhang', required: true })],
            anhaenge: [],
        });

        const maske = maskeAuf(container, 'Als Eingangsrechnung');

        expect(maske.textContent).toContain('keine Anhänge');
        expect(maskenknopf(maske).disabled).toBe(true);

        unmount();
    });

    it('bietet eine unbekannte Feldart weiterhin NICHT an — und sagt es', () => {
        /*
         * Die Gegenprobe zu den drei neuen Arten: Die Liste ist nicht
         * gewachsen zu „alles geht". Eine Art, die hier niemand zeichnen kann,
         * ist kein toter Knopf und kein stilles Weglassen.
         */
        const { container, unmount } = leiste({
            werkzeuge: [mitFeld({ name: 'datum', label: 'Fällig am', type: 'datum', required: true })],
        });

        expect(container.querySelector(`${INHALT} button`)).toBeNull();
        expect(container.textContent).toContain('nicht erfassen');

        unmount();
    });

    it('zeichnet zwei Felder verschiedener Art in EINER Maske', () => {
        /*
         * Der Fall aus dem Task: „Aufgabe anhaengen" braucht die Aufgabe, den
         * Anhang und eine Notiz. Zwei Felder nebeneinander in der Reihe waren
         * der Grund fuer die Maske — sie schoben die Nachbarverben heraus.
         */
        const { container, unmount } = leiste({
            werkzeuge: [
                werkzeug({
                    key: 'aufgabe',
                    label: 'Aufgabe anhängen',
                    fields: [
                        { name: 'anhang', label: 'Anhang', type: 'anhang', multiple: true } as never,
                        { name: 'notiz', label: 'Notiz', type: 'text' } as never,
                    ],
                }),
            ],
            anhaenge: ANHAENGE,
        });

        const maske = maskeAuf(container, 'Aufgabe anhängen');

        expect(maske.querySelectorAll('input[type="checkbox"]')).toHaveLength(2);
        expect(maske.querySelector('input[type="text"]')).not.toBeNull();
        // Kein Pflichtfeld dabei: Dann darf der Knopf von Anfang an zu.
        expect(maskenknopf(maske).disabled).toBe(false);

        unmount();
    });
});

describe('der Auswaehler mit Suche (#9145)', () => {
    /*
     * Ein Kontakt wird aus zwoelftausend gewaehlt. Bis v0.203.0 gab es dafuer
     * nur `verweis()`: Das Verb wurde zum Link und fuehrte ins andere System.
     *
     * Die Tastaturbedienung ist hier eigens geprueft, und zwar mit einem
     * selbst ausgeloesten `keydown`: `mail-2b` hat am 1.11.2026 nachgemessen,
     * dass ein solches Ereignis die HANDLER der Seite durchlaeuft, aber keine
     * Vorgabewirkung des Browsers ausloest. Also ist genau das pruefbar, was
     * an Handlern haengt — und darum haengt die Bedienung an Handlern.
     */
    const TREFFER = [
        { value: '4471', label: 'Angebot Meier überarbeiten', hint: 'Bauvorhaben Meier' },
        { value: '4502', label: 'Meier: Rückfrage Liefertermin' },
    ];

    function mitSuche(quelle?: MailWerkzeugleisteProps['auswahlQuelle']) {
        return leiste({
            werkzeuge: [
                werkzeug({
                    key: 'aufgabe',
                    label: 'Aufgabe anhängen',
                    fields: [{ name: 'aufgabe', label: 'Aufgabe', type: 'suche', required: true } as never],
                }),
            ],
            auswahlQuelle: quelle ?? (async () => TREFFER),
        });
    }

    /**
     * Eine Taste, wie ein Mensch sie drueckt — und wie sie in jsdom ankommt.
     *
     * `await`, und das ist nicht Vorsicht: Zwei Tastendruecke ohne Pause
     * dazwischen hat React als einen Durchlauf verrechnet, und der Test war
     * damit blind fuer den Unterschied zwischen „laeuft um" und „bleibt am
     * Ende stehen". Aufgefallen an einer Mutationsprobe, die GRUEN blieb,
     * obwohl der Umlauf ausgebaut war.
     *
     * `mail-2b` hat nachgemessen (1.11.2026), dass ein selbst ausgeloestes
     * `keydown` die Handler durchlaeuft, aber keine Vorgabewirkung des
     * Browsers ausloest. Genau darum ist die Bedienung pruefbar — und genau
     * darum muss man die Wirkung abwarten, statt sie zu unterstellen.
     */
    async function taste(element: Element, key: string): Promise<void> {
        (element as HTMLElement).focus();
        element.dispatchEvent(new KeyboardEvent('keydown', { key, code: key, bubbles: true, cancelable: true }));

        await new Promise((r) => setTimeout(r, 10));
    }

    it('fragt beim Aufklappen OHNE Text — die Liste darf nicht leer dastehen', async () => {
        const gefragt: { feld: string; frage: string }[] = [];

        const { container, unmount } = mitSuche(async (w, feld, frage) => {
            gefragt.push({ feld, frage });

            return TREFFER;
        });

        maskeAuf(container, 'Aufgabe anhängen');

        await vi.waitFor(() => expect(container.querySelectorAll('[role="option"]')).toHaveLength(2));

        expect(gefragt).toEqual([{ feld: 'aufgabe', frage: '' }]);
        // Der Hinweis steht dabei: Er unterscheidet zwei aehnliche Namen.
        expect(container.textContent).toContain('Bauvorhaben Meier');

        unmount();
    });

    it('waehlt mit Pfeil runter und Enter — ohne Maus', async () => {
        const gesehen: unknown[] = [];

        const { container, unmount } = leiste({
            werkzeuge: [
                werkzeug({
                    key: 'aufgabe',
                    label: 'Aufgabe anhängen',
                    fields: [{ name: 'aufgabe', label: 'Aufgabe', type: 'suche', required: true } as never],
                }),
            ],
            auswahlQuelle: async () => TREFFER,
            ausloesen: async (w, eingabe) => {
                gesehen.push(eingabe);

                return { ok: true, message: 'Angehängt.' };
            },
        });

        const maske = maskeAuf(container, 'Aufgabe anhängen');

        await vi.waitFor(() => expect(maske.querySelectorAll('[role="option"]')).toHaveLength(2));

        const feld = maske.querySelector('[role="combobox"]')!;

        // Der erste Treffer ist von sich aus hervorgehoben.
        expect(maske.querySelector('[data-highlighted]')?.textContent).toContain('Angebot Meier');

        await taste(feld, 'ArrowDown');

        await vi.waitFor(() => expect(maske.querySelector('[data-highlighted]')?.textContent).toContain('Rückfrage'));

        /*
         * Und die Vorlesehilfe weiss es auch: zweimal dasselbe, weil es zwei
         * Leser sind — `aria-activedescendant` fuer sie, `data-highlighted`
         * fuer eine Messung ohne Farbvergleich.
         */
        expect(feld.getAttribute('aria-activedescendant')).toBe(maske.querySelector('[data-highlighted]')!.id);

        await taste(feld, 'Enter');

        await vi.waitFor(() => expect(maske.textContent).toContain('Rückfrage Liefertermin'));

        // Zugeklappt, mit dem WORT statt der Kennung — die Kennung kennt nur
        // die Trefferliste, und die ist dann weg.
        expect(maske.querySelector('[role="listbox"]')).toBeNull();

        click(maskenknopf(maske));

        await vi.waitFor(() => expect(gesehen).toHaveLength(1));
        expect(gesehen[0]).toEqual({ aufgabe: '4502' });

        unmount();
    });

    it('laeuft am Ende der Liste wieder nach oben', async () => {
        // Zwei Treffer, dreimal runter: Wer am Ende haengenbleibt, muss raten,
        // ob die Taste nicht ankam.
        const { container, unmount } = mitSuche();
        const maske = maskeAuf(container, 'Aufgabe anhängen');

        await vi.waitFor(() => expect(maske.querySelectorAll('[role="option"]')).toHaveLength(2));

        const feld = maske.querySelector('[role="combobox"]')!;

        await taste(feld, 'ArrowDown');
        await taste(feld, 'ArrowDown');

        await vi.waitFor(() => expect(maske.querySelector('[data-highlighted]')?.textContent).toContain('Angebot Meier'));

        await taste(feld, 'ArrowUp');

        await vi.waitFor(() => expect(maske.querySelector('[data-highlighted]')?.textContent).toContain('Rückfrage'));

        unmount();
    });

    it('haelt den Knopf aus, solange nichts gewaehlt ist', async () => {
        const { container, unmount } = mitSuche();
        const maske = maskeAuf(container, 'Aufgabe anhängen');

        await vi.waitFor(() => expect(maske.querySelectorAll('[role="option"]')).toHaveLength(2));

        expect(maskenknopf(maske).disabled).toBe(true);

        await taste(maske.querySelector('[role="combobox"]')!, 'Enter');

        await vi.waitFor(() => expect(maskenknopf(maske).disabled).toBe(false));

        unmount();
    });

    it('sagt es, wenn die Suche nichts findet', async () => {
        // Und zwar als Satz, nicht als leeres Kaestchen: Das saehe aus wie
        // „laedt noch".
        const { container, unmount } = mitSuche(async () => []);
        const maske = maskeAuf(container, 'Aufgabe anhängen');

        await vi.waitFor(() => expect(maske.textContent).toContain('keinen Treffer'));

        expect(maske.querySelector('[role="listbox"]')).toBeNull();

        unmount();
    });

    it('bietet das Verb NICHT an, wenn es gar keine Suchquelle gibt', () => {
        /*
         * Ein Suchfeld ohne Quelle waere ein Feld, das aussieht, als suchte
         * es, und nie etwas findet — und der Mensch sucht den Fehler bei
         * seiner Schreibweise. Also gar nicht anbieten und es SAGEN.
         */
        const { container, unmount } = leiste({
            werkzeuge: [
                werkzeug({
                    key: 'aufgabe',
                    label: 'Aufgabe anhängen',
                    fields: [{ name: 'aufgabe', label: 'Aufgabe', type: 'suche', required: true } as never],
                }),
            ],
        });

        expect(container.querySelector(`${INHALT} button`)).toBeNull();
        expect(container.textContent).toContain('nicht erfassen');

        unmount();
    });

    it('verwirft die spaete Antwort und behaelt die frische', async () => {
        /*
         * Der Fehler, den man nur bei schnellem Tippen sieht: Die Liste
         * springt auf die Treffer zu „Mei", waehrend im Feld „Meier" steht.
         *
         * Der Ablauf ist eigens so gebaut, dass die erste Suche wirklich
         * LOSGEHT, bevor die zweite kommt — sonst gaebe es nichts
         * abzubrechen, und der Test waere blind. Die Verzoegerung von 250 ms
         * im Bauteil muss dafuer abgewartet werden; ein Test, der schneller
         * tippt als ein Mensch, prueft die Abbruchlogik nie.
         *
         * Genau das war der erste Entwurf dieses Tests: Er blieb gruen,
         * nachdem die Abbruchpruefung ausgebaut war.
         */
        const { container, unmount } = mitSuche(async (w, feld, frage) => {
            if (frage === '') {
                return [{ value: 'alt', label: 'Erster Aufschlag' }];
            }

            // „Mei" ist langsam, „Meier" schnell — so kommt die alte Antwort
            // nach der neuen an.
            await new Promise((r) => setTimeout(r, frage === 'Mei' ? 400 : 10));

            return [{ value: frage, label: `Treffer zu ${frage}` }];
        });

        const maske = maskeAuf(container, 'Aufgabe anhängen');

        await vi.waitFor(() => expect(maske.textContent).toContain('Erster Aufschlag'));

        const feld = maske.querySelector('[role="combobox"]') as HTMLInputElement;

        waehle(feld, 'Mei');
        // Warten, bis die Verzoegerung abgelaufen und die Suche unterwegs ist.
        await new Promise((r) => setTimeout(r, 300));

        waehle(feld, 'Meier');

        await vi.waitFor(() => expect(maske.textContent).toContain('Treffer zu Meier'), { timeout: 2000 });

        // Und jetzt lange genug warten, dass die ALTE Antwort ankommen koennte.
        await new Promise((r) => setTimeout(r, 500));

        expect(maske.textContent).toContain('Treffer zu Meier');
        expect(maske.textContent).not.toContain('Treffer zu Mei<');

        unmount();
    });
});
