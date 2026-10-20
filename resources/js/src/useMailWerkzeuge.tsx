import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Die Werkzeugleiste an einer Mail — die Oberflächen-Hälfte (#8313/#8316).
 *
 * ## Warum dieser Haken erst am 20.10.2026 entstand
 *
 * Der Server-Teil war am 19.10. fertig: Verzeichnis, zwei Endpunkte, vier
 * angemeldete Verben, Tests in allen vier Produkten grün. Und **kein
 * Frontend rief ihn ab.** Gemessen am 20.10.: null Dateien in CRM,
 * Verwaltung und Manager berührten `/tools`.
 *
 * Das ist die teuerste Sorte Lücke, weil sie nach Fortschritt aussieht. Die
 * Tests waren grün, die Routen standen, die Verben antworteten — nur sah sie
 * niemand. Für den Menschen davor hatte sich nichts geändert.
 *
 * ## „Nichts da" und „niemand hat geantwortet" sind zwei Antworten
 *
 * Die wichtigste Regel dieses Hakens, und sie ist in diesem Paket schon
 * zweimal bezahlt (Zuständigkeit, Massenaktion): Eine leere Leiste heißt
 * „hier ist nichts zu tun". Wenn aber die Verwaltung gerade nicht antwortet,
 * ist die richtige Aussage „das Ticket-System antwortet nicht" — und nicht
 * dasselbe Nichts.
 *
 * Deshalb reicht der Haken `unerreichbar` und `gescheitert` getrennt heraus,
 * und deshalb ist `leerAberVollstaendig` eine eigene Auskunft: Nur wenn
 * niemand fehlt, darf die Oberfläche „keine Werkzeuge" sagen.
 */

/** Eine Auswahlmöglichkeit in einem Feld. */
export interface MailWerkzeugFeldOption {
    value: string;
    label: string;
}

/**
 * Eine Eingabe, die ein Verb vor dem Auslösen braucht.
 *
 * `type` ist die Angabe, welche Art von Eingabe gemeint ist — heute gibt es
 * genau eine, `select`. Was die Leiste nicht zeichnen kann, bietet sie nicht
 * als Knopf an: Ein Verb, das seine Pflichtangabe nicht einsammeln kann, ist
 * ein Knopf, der zuverlässig scheitert.
 *
 * Diese Form stand bis zum 20.10.2026 als `Record<string, unknown>` hier —
 * der Server schickt aber eine LISTE. Das fiel nicht auf, weil kein Werkzeug
 * Felder benutzte und die Leiste sie gar nicht zeichnete: eine Zusage in der
 * Drahtform, hinter der nichts stand.
 */
export interface MailWerkzeugFeld {
    name: string;
    label: string;
    type: string;
    required: boolean;
    options?: MailWerkzeugFeldOption[];
}

/** Ein Verb, so wie der Server es schickt (siehe `Werkzeug::toArray()`). */
export interface MailWerkzeug {
    key: string;
    label: string;
    icon: string | null;
    order: number;
    group: string | null;
    /** Eingaben, die das Verb braucht. Leer heißt: ein Klick genügt. */
    fields: MailWerkzeugFeld[] | null;
    /** Rückfrage vor dem Auslösen, als Satz. */
    confirm: string | null;
    /** Was es schon gibt — „Ticket #123", mit Verweis dorthin. */
    state: { label: string; url: string | null } | null;
    /**
     * Wohin das Verb führt, wenn es nicht auslöst.
     *
     * Gesetzt heißt: NICHT auslösen. Dieselbe Regel wie `fuehrtHin()` auf der
     * Serverseite — ein Kontakt wird aus zwölftausend gewählt, und diesen
     * Auswähler kann ein fremdes System nicht zeichnen.
     */
    url: string | null;
    /** `null` für ein eigenes Verb, sonst das Kürzel des Zielsystems. */
    system: string | null;
    system_label: string | null;
}

export interface MailWerkzeugeAntwort {
    tools: MailWerkzeug[];
    /** Systeme, die nicht geantwortet haben. Kürzel oder Namen. */
    unreachable: string[];
    /** Verben, die geantwortet haben und dabei gescheitert sind. */
    failed: string[];
}

/** Die Nachricht, um die es geht. Ohne `message_id` fragt der Haken nicht. */
export interface WerkzeugNachricht {
    message_id?: string | null;
    in_reply_to?: string | null;
    references?: string | null;
    subject?: string | null;
    from_address?: string | null;
}

export interface MailWerkzeugeOptionen {
    /** `GET` hierher. Beispiel: `/mailbox/42/tools`. */
    endpoint: string | null;
    /** `POST` hierher. Beispiel: `/mailbox/42/tools/run`. */
    ausloesenEndpoint: string | null;
    nachricht: WerkzeugNachricht | null;
    csrfToken?: string | null;
    /** Für Tests. */
    fetch?: typeof globalThis.fetch;
}

export interface Ausloeseergebnis {
    ok: boolean;
    message: string;
    state: { label: string; url: string | null } | null;
}

const LEER: MailWerkzeugeAntwort = { tools: [], unreachable: [], failed: [] };

function kopfzeilen(csrfToken?: string | null): Record<string, string> {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
    };
}

/** Die Angaben, mit denen jedes System dieselbe Mail findet. */
function kennzeichen(nachricht: WerkzeugNachricht): Record<string, string> {
    const felder: Record<string, string> = { message_id: String(nachricht.message_id ?? '') };

    // Nur setzen, was es gibt: Ein leerer `references`-Kopf ist etwas anderes
    // als ein fehlender, und der Server validiert beide verschieden.
    if (nachricht.in_reply_to) felder.in_reply_to = nachricht.in_reply_to;
    if (nachricht.references) felder.references = nachricht.references;
    if (nachricht.subject) felder.subject = nachricht.subject;
    if (nachricht.from_address) felder.from = nachricht.from_address;

    return felder;
}

export function useMailWerkzeuge(optionen: MailWerkzeugeOptionen) {
    const { endpoint, ausloesenEndpoint, nachricht, csrfToken } = optionen;
    const doFetch = optionen.fetch ?? globalThis.fetch;

    const [antwort, setAntwort] = useState<MailWerkzeugeAntwort>(LEER);
    const [laedt, setLaedt] = useState(false);

    /**
     * Ob die letzte Anfrage überhaupt angekommen ist.
     *
     * `null` heißt: noch nicht gefragt. Das ist NICHT dasselbe wie „gefragt
     * und nichts bekommen" — sonst zeigt die Leiste beim ersten Rendern eine
     * Fehlermeldung, bevor sie etwas versucht hat.
     */
    const [erreicht, setErreicht] = useState<boolean | null>(null);

    /*
     * Welche Anfrage die neueste ist. Wer schnell durch die Liste klickt,
     * hat drei laufende Anfragen, und die Antwort auf Mail 1 kann nach der
     * auf Mail 3 eintreffen — dann steht die Leiste von Mail 1 an Mail 3,
     * und ein Klick legt das Ticket an der falschen Mail an.
     */
    const laufende = useRef(0);

    const laden = useCallback(async () => {
        if (!endpoint || !nachricht?.message_id) {
            setAntwort(LEER);
            setErreicht(null);

            return;
        }

        const meine = ++laufende.current;
        setLaedt(true);

        try {
            const url = `${endpoint}?${new URLSearchParams(kennzeichen(nachricht)).toString()}`;
            const response = await doFetch(url, {
                headers: kopfzeilen(csrfToken),
                credentials: 'same-origin',
            });

            if (meine !== laufende.current) {
                return;
            }

            if (!response.ok) {
                setAntwort(LEER);
                setErreicht(false);

                return;
            }

            const daten = (await response.json()) as Partial<MailWerkzeugeAntwort>;

            if (meine !== laufende.current) {
                return;
            }

            setAntwort({
                tools: Array.isArray(daten.tools) ? daten.tools : [],
                unreachable: Array.isArray(daten.unreachable) ? daten.unreachable : [],
                failed: Array.isArray(daten.failed) ? daten.failed : [],
            });
            setErreicht(true);
        } catch {
            if (meine === laufende.current) {
                setAntwort(LEER);
                setErreicht(false);
            }
        } finally {
            if (meine === laufende.current) {
                setLaedt(false);
            }
        }
    }, [endpoint, csrfToken, doFetch, nachricht?.message_id, nachricht?.in_reply_to, nachricht?.references, nachricht?.subject, nachricht?.from_address]);

    useEffect(() => {
        void laden();
    }, [laden]);

    /**
     * Ein Verb auslösen.
     *
     * Verweis-Verben werden hier ABGEWIESEN und nicht geschickt. Die Regel
     * steht auch auf dem Server, und das ist Absicht — aber sie hier zu
     * wiederholen erspart einen Netzweg für eine Antwort, die schon
     * feststeht, und verhindert, dass eine Oberfläche einen Auswähler
     * übergeht, den es aus gutem Grund gibt.
     */
    const ausloesen = useCallback(
        async (werkzeug: MailWerkzeug, eingabe: Record<string, unknown> = {}): Promise<Ausloeseergebnis> => {
            if (werkzeug.url) {
                return { ok: false, message: 'Dieses Verb führt zum Zielsystem; dort wird es ausgelöst.', state: null };
            }

            if (!ausloesenEndpoint || !nachricht?.message_id) {
                return { ok: false, message: 'Zu dieser Nachricht fehlen die Angaben, mit denen sie zu finden ist.', state: null };
            }

            try {
                const response = await doFetch(ausloesenEndpoint, {
                    method: 'POST',
                    headers: kopfzeilen(csrfToken),
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        action: werkzeug.key,
                        // Genau zurück, was im Werkzeug stand. „Aufgabe
                        // anhängen" gibt es in zwei Systemen, und welches
                        // gemeint war, hat der Mensch beim Klick entschieden.
                        system: werkzeug.system,
                        ...kennzeichen(nachricht),
                        input: eingabe,
                    }),
                });

                if (!response.ok) {
                    /*
                     * Der Server antwortet auch bei einem gescheiterten Verb
                     * mit 200 und `ok: false`. Ein echter Nicht-2xx ist
                     * deshalb etwas anderes: die Leitung oder die Anmeldung,
                     * nicht das Verb. Beides als „ging nicht" zu zeigen
                     * hiesse, jemanden die Eingabe ändern zu lassen, wo
                     * nichts zu ändern ist.
                     */
                    return {
                        ok: false,
                        message:
                            response.status === 401 || response.status === 419
                                ? 'Die Sitzung ist abgelaufen. Bitte neu anmelden.'
                                : 'Das Zielsystem ist gerade nicht erreichbar.',
                        state: null,
                    };
                }

                const daten = (await response.json()) as Partial<Ausloeseergebnis> & { label?: string };

                // Nach einem erfolgreichen Verb hat sich der ZUSTAND geändert
                // („Ticket #123 gibt es jetzt"). Ohne neu zu laden bietet die
                // Leiste es ein zweites Mal an.
                if (daten.ok) {
                    void laden();
                }

                return {
                    ok: Boolean(daten.ok),
                    message: String(daten.message ?? daten.label ?? ''),
                    state: daten.state ?? null,
                };
            } catch {
                return { ok: false, message: 'Das Zielsystem ist gerade nicht erreichbar.', state: null };
            }
        },
        [ausloesenEndpoint, csrfToken, doFetch, laden, nachricht],
    );

    return {
        werkzeuge: antwort.tools,
        unerreichbar: antwort.unreachable,
        gescheitert: antwort.failed,
        laedt,
        /**
         * Ob die Leiste „keine Werkzeuge" sagen DARF.
         *
         * Nur wenn gefragt wurde, die Antwort ankam und niemand fehlt. Sonst
         * ist die leere Leiste eine fehlende Auskunft und keine Aussage über
         * den Bestand — dieselbe Regel wie bei der Zuständigkeit.
         */
        leerAberVollstaendig:
            erreicht === true && antwort.tools.length === 0 && antwort.unreachable.length === 0 && antwort.failed.length === 0,
        /** `false` heißt: Die Frage kam nicht durch. `null` heißt: noch nicht gefragt. */
        erreicht,
        neuLaden: laden,
        ausloesen,
    };
}
