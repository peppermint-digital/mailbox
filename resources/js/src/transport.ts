/**
 * Talking to a mailbox endpoint.
 *
 * The addresses belong to the product; what happens around a request does not.
 * Every product would write the same headers, the same credentials handling,
 * and — the part that actually matters — the same judgement about what counts
 * as success.
 *
 * ## "HTTP 200" is not an answer for a bulk action
 *
 * A bulk endpoint answers 200 with `success: true` even when it caught no
 * message at all. Until 13.09.2026 the mail browser ended there: someone could
 * tick five mails, hit delete, and see neither an error nor an effect. The
 * count has to be read and judged, not the status code.
 *
 * ## The CSRF token comes in
 *
 * Where it is kept is a product decision — a meta tag in one app, a cookie in
 * the next. The package asks for the value, not for the place.
 */

export interface MailRequestOptions {
    /** The CSRF token to send. Read it wherever your product keeps it. */
    csrfToken: string;
    /** Injectable for tests and for products with their own client. */
    fetch?: typeof globalThis.fetch;
    signal?: AbortSignal;
}

/** What a bulk endpoint reports back about its own work. */
export interface BulkOutcome {
    processed: number;
    failed: number;
}

function headersFor(csrfToken: string): Record<string, string> {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken,
    };
}

/**
 * A request whose only question is "did it work".
 *
 * Returns false rather than throwing: a failed mailbox action is an expected
 * outcome, not an exception, and every caller would otherwise wrap it.
 */
export async function postJson(
    url: string,
    body: Record<string, unknown>,
    options: MailRequestOptions & {
        /**
         * Fuer die seltene Aktion, die loescht statt anzulegen — etwa das
         * Aufheben einer Wiedervorlage (A7, #9116). Vorgabe bleibt `POST`;
         * der Name der Funktion stimmt dann weiter.
         */
        method?: 'POST' | 'DELETE';
    },
): Promise<boolean> {
    const doFetch = options.fetch ?? globalThis.fetch;

    try {
        const response = await doFetch(url, {
            method: options.method ?? 'POST',
            headers: headersFor(options.csrfToken),
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal: options.signal,
        });

        return response.ok;
    } catch {
        return false;
    }
}

/**
 * A request that reports how much it actually did.
 *
 * Returns null when the answer cannot be trusted — a non-OK status, a body
 * that is not JSON (a web server's own 413 page, for instance), or
 * `success: false`. A caller that gets null knows nothing happened; a caller
 * that gets `{processed: 0}` knows the endpoint ran and caught nothing. Those
 * are different, and collapsing them is how "no error, no effect" happens.
 */
export async function postCounted(
    url: string,
    body: Record<string, unknown>,
    options: MailRequestOptions,
): Promise<BulkOutcome | null> {
    const doFetch = options.fetch ?? globalThis.fetch;

    let response: Response;

    try {
        response = await doFetch(url, {
            method: 'POST',
            headers: headersFor(options.csrfToken),
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal: options.signal,
        });
    } catch {
        return null;
    }

    if (!response.ok) {
        return null;
    }

    try {
        const data = (await response.json()) as { success?: boolean; processed?: number; failed?: number };

        if (!data.success) {
            return null;
        }

        return { processed: data.processed ?? 0, failed: data.failed ?? 0 };
    } catch {
        return null;
    }
}

/**
 * Did a bulk action do anything at all?
 *
 * The question every caller asks after `postCounted`, spelled once so nobody
 * writes `result && result.processed > 0` slightly differently the fourth time.
 */
export function didSomething(outcome: BulkOutcome | null): boolean {
    return outcome !== null && outcome.processed > 0;
}

/** Eine Adresse, wie sie der Sende-Endpunkt erwartet. */
export interface SendeAdresse {
    email: string;
    name?: string | null;
}

/** Was eine ausgehende Nachricht ausmacht — ohne die Dateien. */
export interface SendeDaten {
    to: SendeAdresse[];
    cc?: SendeAdresse[];
    bcc?: SendeAdresse[];
    subject: string;
    body_html: string;
    /** Fehlt er, baut `sendeNachricht` ihn aus dem HTML. */
    body_text?: string;
    /** Das Zitat der Ursprungsnachricht, getrennt vom eigenen Text. */
    quoted_html?: string;
    in_reply_to?: string;
    references?: string;
    /** Die zwei Felder eines wiederaufgenommenen Entwurfs. */
    draft_folder?: string;
    draft_uid?: string;
}

export interface SendeErgebnis {
    ok: boolean;
    /** Der Satz fuer den Absender — vom Server, sonst ein eigener. */
    message: string;
    message_id?: string;
    /** `null` heisst: gar nicht versucht, weil der Anbieter selbst ablegt. */
    copied_to_sent?: boolean | null;
}

/**
 * Eine Nachricht senden — mit ihren Dateien.
 *
 * ## Warum das hier liegt und nicht viermal in den Produkten
 *
 * Nachgesehen am 31.10.2026: Die Verfassen-Seiten von CRM, Verwaltung und AI
 * Brain bauen jede dieselbe `fetch`-Zeile — dieselben Kopfzeilen, derselbe
 * Griff nach dem CSRF-Merkmal, dieselbe Auswertung der Antwort. Und alle drei
 * senden **JSON**.
 *
 * Das ist der Grund, aus dem sie keine Dateien anhaengen koennen, und er ist
 * ein anderer als der vermutete: **Nicht der Server nimmt keine** — er nimmt
 * sie seit jeher (`HandlesSending::send()` prueft `attachments.*` als `file`,
 * `hochgeladene()` liest sie aus). Ein Browser bekommt eine Datei nur nicht in
 * JSON unter.
 *
 * Deshalb baut diese Funktion `FormData` und nicht JSON, immer — auch ohne
 * Dateien. Zwei Wege je nach Fall waeren zwei Wege, die auseinanderlaufen, und
 * der seltenere waere der ungeprueftere.
 *
 * ## Was still schiefgehen kann, und wogegen hier gebaut ist
 *
 * Wer versehentlich wieder JSON baut, verliert die Dateien **lautlos**: Der
 * Server sieht kein `attachments`, sendet die Nachricht und meldet Erfolg. Der
 * Absender erfaehrt nie, dass die Rechnung fehlte. Genau diese Fehlerklasse
 * hat Bug #1155 ausgemacht, und sie ist der Grund fuer die Zusicherung
 * daneben.
 *
 * ## Die Form der Felder ist nicht frei
 *
 * Laravel liest Listen aus `FormData` nur als `to[0][email]`. Ein
 * `to` mit JSON darin kaeme als Zeichenkette an und faellt in der Pruefung
 * durch — mit einer Meldung ueber das Feld `to`, die niemand mit „die Adresse
 * steht doch da" zusammenbringt.
 */
export async function sendeNachricht(
    url: string,
    daten: SendeDaten,
    dateien: File[],
    options: MailRequestOptions,
): Promise<SendeErgebnis> {
    const doFetch = options.fetch ?? globalThis.fetch;
    const koffer = new FormData();

    const adressen = (feld: string, liste: SendeAdresse[] | undefined) => {
        (liste ?? []).forEach((adresse, i) => {
            koffer.append(`${feld}[${i}][email]`, adresse.email);
            // `name` nur, wenn es eines gibt: Ein leeres Feld laesst die
            // Pruefung durch, landet aber als `""` im Kopf der Mail.
            if (adresse.name) {
                koffer.append(`${feld}[${i}][name]`, adresse.name);
            }
        });
    };

    adressen('to', daten.to);
    adressen('cc', daten.cc);
    adressen('bcc', daten.bcc);

    koffer.append('subject', daten.subject);
    koffer.append('body_html', daten.body_html);
    /*
     * Der Klartext-Zweig, und er ist kein Beiwerk: Eine Mail ohne ihn ist bei
     * Spamfiltern ein schwaches Signal. Hier gebaut, weil ihn sonst jedes
     * Produkt selbst baut — und zwar jedes mit einem leicht anderen Regex.
     */
    koffer.append('body_text', daten.body_text ?? ausHtml(daten.body_html));

    for (const feld of ['quoted_html', 'in_reply_to', 'references', 'draft_folder', 'draft_uid'] as const) {
        const wert = daten[feld];
        if (wert !== undefined && wert !== '') {
            koffer.append(feld, wert);
        }
    }

    dateien.forEach((datei) => koffer.append('attachments[]', datei));

    let antwort: Response;

    try {
        antwort = await doFetch(url, {
            method: 'POST',
            /*
             * KEIN `Content-Type`. Den setzt der Browser bei `FormData` selbst
             * — samt der `boundary`, die er dafuer wuerfelt. Wer ihn von Hand
             * setzt, liefert eine Grenze mit, die es nicht gibt, und der
             * Server findet kein einziges Feld.
             */
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': options.csrfToken,
            },
            credentials: 'same-origin',
            body: koffer,
            signal: options.signal,
        });
    } catch {
        return { ok: false, message: 'Die Nachricht konnte nicht versendet werden: keine Verbindung zum Server.' };
    }

    let inhalt: Record<string, unknown> = {};

    try {
        inhalt = (await antwort.json()) as Record<string, unknown>;
    } catch {
        // Eine Antwort, die kein JSON ist (die 413-Seite eines Webservers bei
        // zu grossen Anhaengen) — die Lage ist trotzdem klar.
    }

    const satz = typeof inhalt.message === 'string' ? inhalt.message : null;

    if (!antwort.ok || inhalt.success !== true) {
        return {
            ok: false,
            message: satz ?? `Die Nachricht konnte nicht versendet werden (HTTP ${antwort.status}).`,
        };
    }

    return {
        ok: true,
        message: satz ?? 'Nachricht gesendet.',
        message_id: typeof inhalt.message_id === 'string' ? inhalt.message_id : undefined,
        copied_to_sent: (inhalt.copied_to_sent ?? null) as boolean | null,
    };
}

/** HTML zu Klartext, einmal statt viermal. */
function ausHtml(html: string): string {
    return html
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}
