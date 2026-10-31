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
    /**
     * Die Fehler AM FELD, wie das Formular sie erwartet: `{ to, subject, … }`.
     *
     * Fehlte bis zum 31.10.2026, und die Folge war sichtbar: Diese Funktion
     * las aus einer 422 nur `message` und warf `errors` weg. CRM, Verwaltung
     * und AI Brain konnten die Eigenschaft `errors` des Formulars darum gar
     * nicht fuellen und zeigten stattdessen eine Meldung oben — also „etwas
     * stimmt nicht" statt „diese Adresse stimmt nicht". Gefunden von `mail-2b`
     * beim Vergleich der vier Verfassen-Seiten.
     *
     * Nicht gesetzt, wenn der Server keine schickt. Ein leeres Objekt waere
     * etwas anderes als „keine Angabe" und liest sich am Formular wie „alle
     * Felder in Ordnung".
     */
    felder?: Record<string, string>;
}

/**
 * Die Feldfehler einer Antwort, auf die Feldnamen des Formulars gebracht.
 *
 * ## Warum hier etwas umbenannt wird
 *
 * Laravel meldet den Fehler an der Stelle, an der er steht: `to.0.email`,
 * `attachments.2`, `cc.1.name`. Das Formular kennt `to`, `attachments`, `cc`.
 * Wer die Schluessel durchreicht, wie sie kommen, bekommt ein `errors`, das
 * fuer jedes Feld „kein Fehler" sagt — und dann sieht ein falscher erster
 * Empfaenger genauso aus wie ein verschluckter Fehler.
 *
 * Also der erste Abschnitt vor dem Punkt. Das ist keine Liste von Sonderfaellen,
 * die mit dem naechsten Feld laenger wird, sondern die Regel, nach der Laravel
 * diese Schluessel baut.
 *
 * ## Je Feld der erste Satz
 *
 * Drei falsche Adressen ergeben drei Saetze unter `to.*`. Am Feld steht einer.
 * Der erste ist die ehrlichste Wahl: Er gehoert zur ersten Stelle, an der man
 * nachsieht. Sie aneinanderzuhaengen gaebe eine Zeile, die unter dem Feld nicht
 * mehr lesbar ist.
 */
function feldfehler(roh: unknown): Record<string, string> | undefined {
    if (roh === null || typeof roh !== 'object') {
        return undefined;
    }

    const felder: Record<string, string> = {};

    for (const [schluessel, wert] of Object.entries(roh as Record<string, unknown>)) {
        const satz = Array.isArray(wert) ? wert.find((eintrag) => typeof eintrag === 'string') : wert;

        if (typeof satz !== 'string' || satz === '') {
            continue;
        }

        const feld = schluessel.split('.')[0] || schluessel;

        if (!(feld in felder)) {
            felder[feld] = satz;
        }
    }

    return Object.keys(felder).length > 0 ? felder : undefined;
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
    const koffer = baueKoffer(daten, dateien, ['quoted_html', 'in_reply_to', 'references', 'draft_folder', 'draft_uid']);

    let antwort: Response;

    try {
        antwort = await doFetch(url, anfrage(koffer, options));
    } catch {
        return { ok: false, message: 'Die Nachricht konnte nicht versendet werden: keine Verbindung zum Server.' };
    }

    const inhalt = await inhaltVon(antwort);
    const satz = typeof inhalt.message === 'string' ? inhalt.message : null;

    if (!antwort.ok || inhalt.success !== true) {
        return {
            ok: false,
            message: satz ?? `Die Nachricht konnte nicht versendet werden (HTTP ${antwort.status}).`,
            felder: feldfehler(inhalt.errors),
        };
    }

    return {
        ok: true,
        message: satz ?? 'Nachricht gesendet.',
        message_id: typeof inhalt.message_id === 'string' ? inhalt.message_id : undefined,
        copied_to_sent: (inhalt.copied_to_sent ?? null) as boolean | null,
    };
}

/**
 * Was ein Entwurf mitbringt — und was er NICHT mitbringen muss.
 *
 * Empfaenger, Betreff und Text sind optional, anders als beim Senden. Ein
 * Entwurf ist der Zustand „noch nicht fertig"; ihn an denselben Pflichtfeldern
 * zu messen hiesse, dass man erst speichern darf, wenn man nicht mehr muss.
 */
export interface EntwurfsDaten {
    to?: SendeAdresse[];
    cc?: SendeAdresse[];
    /**
     * Die Blindkopie. Sie wird MITGESCHICKT und NICHT abgelegt — der Server
     * meldet das mit `bcc_verworfen`, damit das Produkt es sagen kann.
     *
     * Warum sie nicht abgelegt wird, steht in `Http\SpeichertEntwuerfe`: Der
     * Postfach-Vertrag gibt zu einer Nachricht kein `bcc` heraus, und was man
     * schreibt und nicht wiederlesen kann, ist verloren statt gespeichert.
     */
    bcc?: SendeAdresse[];
    subject?: string;
    body_html?: string;
    body_text?: string;
    quoted_html?: string;
    in_reply_to?: string;
    references?: string;
    /** Die Fassung, die ersetzt werden soll. Beide oder keines. */
    replace_folder?: string;
    replace_uid?: string | number;
}

export interface EntwurfsErgebnis {
    ok: boolean;
    message: string;
    /** Wo er liegt — der Entwurfsordner, den der Server gefunden hat. */
    folder?: string;
    /**
     * Die Kennung der neuen Fassung. Das Produkt braucht sie fuer das NAECHSTE
     * Speichern: Ohne sie legt der zweite Klick eine zweite Fassung an statt zu
     * ersetzen. `null` heisst: Der Server nennt keine (IMAP darf das).
     */
    uid?: number | string | null;
    /** Es war ein BCC dabei, und es steht NICHT im abgelegten Entwurf. */
    bcc_verworfen?: boolean;
    /** Die Fehler am Feld, wie bei {@see SendeErgebnis}. */
    felder?: Record<string, string>;
}

/**
 * Einen Entwurf ablegen — und die Vorfassung ersetzen.
 *
 * ## Warum das hier liegt und nicht viermal im Produkt
 *
 * Dasselbe Argument wie bei {@see sendeNachricht}, und derselbe Befund: Vor
 * dem 31.10.2026 hatte den Serverweg nur der Projekt-Manager, in eigenem Code.
 * Die anderen drei konnten einen Entwurf oeffnen und keinen anlegen.
 *
 * Die Nutzlast ist `FormData`, aus demselben Grund wie beim Senden: Ein
 * Browser bekommt eine Datei nicht in JSON unter — und ein Entwurf mit
 * Anhaengen ist der Normalfall, nicht die Ausnahme.
 */
export async function speichereEntwurf(
    url: string,
    daten: EntwurfsDaten,
    dateien: File[],
    options: MailRequestOptions,
): Promise<EntwurfsErgebnis> {
    const doFetch = options.fetch ?? globalThis.fetch;
    const koffer = baueKoffer(daten, dateien, [
        'quoted_html',
        'in_reply_to',
        'references',
        'replace_folder',
        'replace_uid',
    ]);

    let antwort: Response;

    try {
        antwort = await doFetch(url, anfrage(koffer, options));
    } catch {
        return { ok: false, message: 'Der Entwurf konnte nicht gespeichert werden: keine Verbindung zum Server.' };
    }

    const inhalt = await inhaltVon(antwort);
    const satz = typeof inhalt.message === 'string' ? inhalt.message : null;

    if (!antwort.ok || inhalt.success !== true) {
        return {
            ok: false,
            message: satz ?? `Der Entwurf konnte nicht gespeichert werden (HTTP ${antwort.status}).`,
            felder: feldfehler(inhalt.errors),
        };
    }

    return {
        ok: true,
        message: satz ?? 'Entwurf gespeichert.',
        folder: typeof inhalt.folder === 'string' ? inhalt.folder : undefined,
        uid: (inhalt.uid ?? null) as number | string | null,
        bcc_verworfen: inhalt.bcc_verworfen === true,
    };
}

/**
 * Die Nutzlast, die `HandlesSending` und `SpeichertEntwuerfe` beide lesen.
 *
 * EINE Stelle, und das ist der Punkt: Senden und Speichern unterscheiden sich
 * nur darin, welche Zusatzfelder mitgehen und was in der Antwort steht. Zwei
 * Abschriften dieses Blocks waeren zwei Gelegenheiten, die Adressform zu
 * vergessen — und ein Entwurf, der seine Anhaenge anders anhaengt als eine
 * gesendete Mail, ist genau die Sorte Unterschied, die niemand sucht.
 *
 * @param  felder  Welche Zeichenketten-Felder durchgereicht werden.
 */
function baueKoffer(
    daten: SendeDaten | EntwurfsDaten,
    dateien: File[],
    felder: readonly string[],
): FormData {
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

    const rumpf = daten.body_html ?? '';

    koffer.append('subject', daten.subject ?? '');
    koffer.append('body_html', rumpf);
    /*
     * Der Klartext-Zweig, und er ist kein Beiwerk: Eine Mail ohne ihn ist bei
     * Spamfiltern ein schwaches Signal. Hier gebaut, weil ihn sonst jedes
     * Produkt selbst baut — und zwar jedes mit einem leicht anderen Regex.
     */
    koffer.append('body_text', daten.body_text ?? ausHtml(rumpf));

    for (const feld of felder) {
        const wert = (daten as Record<string, unknown>)[feld];

        if (wert !== undefined && wert !== null && wert !== '') {
            koffer.append(feld, String(wert));
        }
    }

    dateien.forEach((datei) => koffer.append('attachments[]', datei));

    return koffer;
}

/** Die Anfrage selbst — Kopfzeilen und Beiwerk, fuer beide Wege dieselben. */
function anfrage(koffer: FormData, options: MailRequestOptions): RequestInit {
    return {
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
    };
}

/**
 * Die Antwort als Objekt — auch wenn sie keines ist.
 *
 * Eine Antwort, die kein JSON ist (die 413-Seite eines Webservers bei zu
 * grossen Anhaengen), ist kein Grund abzustuerzen: Die Lage ist aus dem
 * Statuscode klar.
 */
async function inhaltVon(antwort: Response): Promise<Record<string, unknown>> {
    try {
        return (await antwort.json()) as Record<string, unknown>;
    } catch {
        return {};
    }
}

/** HTML zu Klartext, einmal statt viermal. */
function ausHtml(html: string): string {
    return html
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}
