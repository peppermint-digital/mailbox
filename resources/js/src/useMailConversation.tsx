import { useEffect, useState } from 'react';
import type { ConversationEntry } from './MailConversation';

/**
 * Den Gespraechsverlauf zur geoeffneten Nachricht holen.
 *
 * ## Warum das hier liegt und nicht in den Produkten
 *
 * Es lag in den Produkten — genau einmal, in AI Brain, und nirgends sonst.
 * Dabei ist an diesem Ablauf nichts produktspezifisch ausser der Adresse:
 * fragen, ob es einen vollstaendigen Verlauf gibt; den Umschalter nur dann
 * zeigen; beim Klick laden. Wer das je Produkt neu schreibt, schreibt dreimal
 * dasselbe — und zweimal davon gar nicht.
 *
 * ## Warum erst gefragt und dann gezeigt wird
 *
 * Die Ablage beginnt an einem Stichtag. Eine Konversation, die davor begann,
 * liegt nur teilweise darin — und ein Chat, der mit der dritten Antwort
 * anfaengt, sieht nicht aus wie „unvollstaendig", sondern wie „so war es".
 *
 * Deshalb wird zuerst gefragt, ob ein VOLLSTAENDIGER Verlauf vorliegt, und der
 * Umschalter erscheint nur dann. Ein Knopf, der beim Klick erklaeren muss,
 * warum er nichts kann, ist schlimmer als keiner.
 */
export interface ConversationRoutes {
    /** Gibt es einen vollstaendigen Verlauf? Wird bei JEDER geoeffneten Nachricht gefragt. */
    available(accountId: number | string, thread: string): string;
    /** Der Verlauf selbst — erst auf Klick. */
    conversation(accountId: number | string, thread: string): string;
}

/**
 * Die Adressen, wie alle drei Produkte sie fuehren. Nur der Praefix wechselt.
 *
 * Der Kettenschluessel ist im Regelfall eine Message-ID und darf Schraegstriche
 * enthalten — er gehoert deshalb in die Abfrage und nicht in den Pfad.
 */
export function conversationRoutes(prefix = '/mailbox'): ConversationRoutes {
    return {
        available: (konto, kette) =>
            `${prefix}/${konto}/conversation-available?thread=${encodeURIComponent(kette)}`,
        conversation: (konto, kette) => `${prefix}/${konto}/conversation?thread=${encodeURIComponent(kette)}`,
    };
}

export interface UseMailConversationOptions {
    routes: ConversationRoutes;
    /** Das geoeffnete Postfach. `null`, solange keines gewaehlt ist. */
    accountId: number | string | null;
    /** Die Kette der geoeffneten Nachricht. Leer heisst: keine offen. */
    thread: string;
    /** Injizierbar fuer Tests und fuer Produkte mit eigenem Client. */
    fetch?: typeof globalThis.fetch;
}

export interface MailConversationState {
    /** Liegt ein vollstaendiger Verlauf vor? Nur dann gibt es etwas zu zeigen. */
    available: boolean;
    /** Wird der Verlauf gerade statt der Nachricht gezeigt? */
    on: boolean;
    entries: ConversationEntry[];
    loading: boolean;
    show(): void;
    hide(): void;
}

const KOPFZEILEN = { Accept: 'application/json' };

export function useMailConversation({
    routes,
    accountId,
    thread,
    fetch: injected,
}: UseMailConversationOptions): MailConversationState {
    const doFetch = injected ?? ((...args: Parameters<typeof globalThis.fetch>) => globalThis.fetch(...args));

    const [available, setAvailable] = useState(false);
    const [on, setOn] = useState(false);
    const [entries, setEntries] = useState<ConversationEntry[]>([]);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        // Jede neu geoeffnete Nachricht beginnt ohne Verlauf. Den alten
        // stehenzulassen zeigte fuer einen Augenblick die falsche Unterhaltung.
        /* eslint-disable-next-line react-hooks/set-state-in-effect -- siehe Kommentar */
        setOn(false);
        setEntries([]);

        if (accountId === null || thread === '') {
            setAvailable(false);

            return;
        }

        const abbruch = new AbortController();

        void (async () => {
            try {
                const antwort = await doFetch(routes.available(accountId, thread), {
                    headers: KOPFZEILEN,
                    credentials: 'same-origin',
                    signal: abbruch.signal,
                });
                const daten = await antwort.json();
                setAvailable(Boolean(daten.available));
            } catch {
                // Kein Verlauf ist kein Fehler, ueber den jemand etwas lesen
                // muesste — die normale Ansicht steht ja da.
                setAvailable(false);
            }
        })();

        return () => abbruch.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- haengt an der geoeffneten Nachricht
    }, [accountId, thread]);

    return {
        available,
        on,
        entries,
        loading,
        hide: () => setOn(false),
        show: () => {
            if (accountId === null || thread === '') {
                return;
            }

            setOn(true);
            setLoading(true);

            void (async () => {
                try {
                    const antwort = await doFetch(routes.conversation(accountId, thread), {
                        headers: KOPFZEILEN,
                        credentials: 'same-origin',
                    });
                    const daten = await antwort.json();
                    setEntries(daten.entries ?? []);
                } catch {
                    // Dasselbe wie oben: lieber ein leerer Verlauf mit dem Weg
                    // zurueck als eine Fehlermeldung ueber eine Lesehilfe.
                    setEntries([]);
                } finally {
                    setLoading(false);
                }
            })();
        },
    };
}
