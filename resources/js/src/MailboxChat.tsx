import { Loader2, Mic } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';

/**
 * Chat with the AI Brain mailbox agent.
 *
 * ## Why this is in the package and not in each product
 *
 * The shape of the conversation is not a product decision. What a status means,
 * how often to ask again, what to show while the agent is thinking — every
 * product would answer those the same way, and the interesting answers are the
 * ones that took a bug to find:
 *
 * - `needs_input` was invisible for a long time. The agent waits for a reply and
 *   does nothing on its own; whoever cannot see that holds it for busy and waits
 *   too. Both sides wait for each other and nothing happens.
 * - The polling runs on a freshly set `setTimeout`, not on `setInterval`. With an
 *   interval the rate would have to be torn down and re-set on every change
 *   between idle and working, and two requests overtaking each other is the
 *   result.
 * - A send that fails shows the server's reason. A bare "sending failed" left
 *   one bug undiagnosable for a week.
 *
 * ## What the product still brings
 *
 * The address (its own route) and — if it has one — voice input. Everything else
 * is here. A product without speech simply passes nothing and gets no
 * microphone, rather than a button that does nothing.
 */

/** One message in the conversation. */
export interface MailboxChatMessage {
    id: number;
    role: string;
    content: string;
    is_pending: boolean;
    created_at: string;
}

/** One working step of the agent. */
export interface MailboxChatActivity {
    type: string;
    label: string;
    ts: string | null;
}

/** What the endpoint answers on a read. */
export interface MailboxChatState {
    messages: MailboxChatMessage[];
    status: string | null;
    activity: MailboxChatActivity[];
    /**
     * Wie viele Nachrichten das Gespraech insgesamt hat — nicht, wie viele
     * mitgeliefert wurden. Fehlt die Angabe, zaehlt die Anzeige das Gelieferte;
     * dann stimmt „N aeltere nicht angezeigt" nur, solange nichts abgeschnitten
     * wurde. Ein aelteres Brain schickt sie nicht, und das darf nichts kaputt
     * machen.
     */
    total?: number;
}

/**
 * Where the conversation lives.
 *
 * `send` returns the reason on failure and null on success — the caller has to
 * be able to show it, which is why it is not a boolean.
 */
export interface MailboxChatSource {
    load(accountId: number, signal?: AbortSignal): Promise<MailboxChatState | null>;
    send(accountId: number, content: string): Promise<string | null>;
}

/** What the microphone slot gets handed. */
export interface MailboxChatControls {
    /** Put text into the input field — a transcript, for instance. */
    insert(text: string): void;
    /** True while a send is in flight; a control should disable itself then. */
    sending: boolean;
}

export interface MailboxChatProps {
    account: { id: number; email: string; name?: string };
    source: MailboxChatSource;
    /**
     * Extra controls next to the input, e.g. a microphone. Rendered as a
     * function so the product can run its own hooks inside its own component.
     */
    controls?: (api: MailboxChatControls) => ReactNode;
    /** Heading — products that call the agent something else say so here. */
    title?: string;
    /**
     * Wie viele Nachrichten hoechstens gezeichnet werden. Der Rest bleibt
     * geladen, aber ungezeichnet — das ist der Unterschied zwischen einem
     * fluessigen und einem hakenden Eingabefeld.
     */
    maxMessages?: number;
}

/**
 * How a state of the agent is named in the status line.
 */
const STATUS_TEXT: Record<string, string> = {
    queued: 'Wartet auf einen freien Platz',
    running: 'Arbeitet',
    needs_input: 'Wartet auf deine Antwort',
    done: 'Bereit',
    failed: 'Abgebrochen',
};

const TYPE_ICON: Record<string, string> = {
    thinking: '💭',
    tool_use: '🔧',
    tool_result: '↩',
    bash: '$',
    text: '💬',
    status: '•',
};

/** Is the agent busy? Asked from two places, so spelled once. */
function arbeitet(status: string | null): boolean {
    return status === 'running' || status === 'queued';
}

/** How often to ask again while the agent works, and while it does not. */
const TAKT_ARBEITEND = 2000;
const TAKT_RUHEND = 8000;

/** Wie viele Nachrichten hoechstens gezeichnet werden. */
const HOECHSTENS = 30;

/** Bis zu wie vielen Pixeln Abstand „am Ende" noch als am Ende gilt. */
const NAH_AM_ENDE = 80;

export function MailboxChat({ account, source, controls, title = 'AI-Brain-Chat', maxMessages = HOECHSTENS }: MailboxChatProps) {
    const accountId = account.id;

    const [messages, setMessages] = useState<MailboxChatMessage[]>([]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [status, setStatus] = useState<string | null>(null);
    const [activity, setActivity] = useState<MailboxChatActivity[]>([]);
    const [gesamt, setGesamt] = useState<number | null>(null);
    const [showActivity, setShowActivity] = useState(false);

    const threadEl = useRef<HTMLDivElement | null>(null);
    const nachSendenUhr = useRef<ReturnType<typeof setTimeout> | null>(null);

    /** Is anything running? Decides the polling rate and the look of the status line. */
    const isWorking = arbeitet(status);
    const isWorkingRef = useRef(isWorking);
    isWorkingRef.current = isWorking;

    const statusText = status ? (STATUS_TEXT[status] ?? status) : null;

    /** The last step — the one line that answers "what is it working on". */
    const lastStep = activity[activity.length - 1] ?? null;

    /**
     * Nur das jüngste Stück zeichnen.
     *
     * Der Takt holt den Verlauf alle zwei Sekunden neu. Bei einem gewachsenen
     * Gespraech zeichnet React dann jedes Mal hunderte Blasen neu — und zwar
     * genau waehrend jemand tippt. Der alte Verlauf wird dabei nicht gelesen,
     * sondern nur bewegt.
     */
    const sichtbar = messages.length > maxMessages ? messages.slice(-maxMessages) : messages;
    // Wie viele fehlen: gegen die ECHTE Gesamtzahl gerechnet, nicht gegen das
    // Gelieferte. Sonst meldet die Zeile „20 ältere nicht angezeigt", wo es 500
    // sind — und eine falsche Zahl ist schlechter als gar keine.
    const verborgen = (gesamt ?? messages.length) - sichtbar.length;

    /**
     * Was „am Ende" heisst. Grosszuegig, weil Zeilenhoehen und Bilder den Wert
     * um ein paar Pixel verschieben — wer lesbar am Ende steht, soll mitlaufen.
     */
    const amEndeRef = useRef(true);

    const merkeObAmEnde = useCallback(() => {
        const el = threadEl.current;

        if (el) {
            amEndeRef.current = el.scrollHeight - el.scrollTop - el.clientHeight <= NAH_AM_ENDE;
        }
    }, []);

    // Ans Ende scrollen — aber NUR, wenn der Benutzer dort auch steht.
    //
    // Vorher wurde bei jedem Eintreffen gescrollt. Wer hochscrollte, um etwas
    // nachzulesen, wurde beim naechsten Takt (2–8 s) wieder ans Ende gerissen;
    // Zuruecklesen war damit unmoeglich. Mitlaufen ist richtig, solange man am
    // Ende steht, und falsch, sobald jemand sucht.
    //
    // `useLayoutEffect`, damit es vor dem Zeichnen passiert und nicht sichtbar
    // springt.
    useLayoutEffect(() => {
        if (threadEl.current && amEndeRef.current) {
            threadEl.current.scrollTop = threadEl.current.scrollHeight;
        }
    }, [messages]);

    // Mirror for "is the conversation still empty" — only so `load` needs no
    // dependency on `messages` and the polling does not restart on every arrival.
    const messagesLeerRef = useRef(true);
    messagesLeerRef.current = messages.length === 0;

    const load = useCallback(
        async (signal?: AbortSignal) => {
            if (!accountId) {
                return;
            }

            setLoading(messagesLeerRef.current);

            try {
                const zustand = await source.load(accountId, signal);

                if (zustand) {
                    setMessages(zustand.messages);
                    setStatus(zustand.status);
                    setActivity(zustand.activity);
                    setGesamt(zustand.total ?? null);
                    // Straight into the ref, not only via the render: the next
                    // interval is set in the `.then` of this very call, and the
                    // re-render has not happened by then. Without this line
                    // someone who opens the chat WHILE the agent works waits
                    // eight seconds for the first sign of life — in exactly the
                    // situation where they are watching.
                    isWorkingRef.current = arbeitet(zustand.status);
                }
            } finally {
                setLoading(false);
            }
        },
        [accountId, source],
    );

    useEffect(() => {
        const abbruch = new AbortController();
        let uhr: ReturnType<typeof setTimeout> | null = null;

        setMessages([]);
        setStatus(null);
        setActivity([]);
        setGesamt(null);
        // Ein neues Postfach faengt unten an. Ohne das bliebe „nicht am Ende"
        // vom vorigen Gespraech haengen, und der neue Verlauf liefe nie mit.
        amEndeRef.current = true;

        const takten = () => {
            uhr = setTimeout(
                async () => {
                    await load(abbruch.signal);
                    if (!abbruch.signal.aborted) {
                        takten();
                    }
                },
                isWorkingRef.current ? TAKT_ARBEITEND : TAKT_RUHEND,
            );
        };

        void load(abbruch.signal).then(() => {
            if (!abbruch.signal.aborted) {
                takten();
            }
        });

        return () => {
            abbruch.abort();
            if (uhr) {
                clearTimeout(uhr);
            }
        };
    }, [load]);

    useEffect(
        () => () => {
            if (nachSendenUhr.current) {
                clearTimeout(nachSendenUhr.current);
            }
        },
        [],
    );

    /** Put text into the input field — what the microphone hands over. */
    const insert = useCallback((text: string) => {
        setInput((vorher) => (vorher ? `${vorher} ${text}` : text));
    }, []);

    const send = async (event: FormEvent) => {
        event.preventDefault();

        const content = input.trim();
        if (!content || sending) {
            return;
        }
        setSending(true);
        setError(null);
        setMessages((vorher) => [...vorher, { id: Date.now(), role: 'user', content, is_pending: false, created_at: '' }]);
        setInput('');

        try {
            const grund = await source.send(accountId, content);

            if (grund) {
                setError(grund);
            }
        } finally {
            setSending(false);
            if (nachSendenUhr.current) {
                clearTimeout(nachSendenUhr.current);
            }
            nachSendenUhr.current = setTimeout(() => void load(), 1500);
        }
    };

    return (
        <div className="flex h-full flex-col">
            <div className="flex items-center gap-2 border-b px-3 py-2 text-sm font-medium">
                <span className="shrink-0">{title}</span>
                {/* `truncate` only bites in a flex row with `min-w-0`: without it the
                    box grows with the address instead of shortening it. */}
                <span className="min-w-0 truncate text-xs text-gray-400">{account.email}</span>
            </div>

            {/* Status line: what it is doing, and what on. */}
            {statusText && (
                <div className="border-b bg-muted/40 px-3 py-1.5 text-xs">
                    <div className="flex items-center gap-2">
                        <span
                            className={`h-2 w-2 shrink-0 rounded-full ${
                                isWorking
                                    ? 'animate-pulse bg-blue-500'
                                    : status === 'needs_input'
                                      ? 'bg-amber-500'
                                      : status === 'failed'
                                        ? 'bg-red-500'
                                        : 'bg-gray-300'
                            }`}
                        />
                        <span className="font-medium">{statusText}</span>
                        {/* The last step in the same line: it answers "what is it
                            working on" without anyone having to unfold something. */}
                        {isWorking && lastStep && (
                            <span className="min-w-0 flex-1 truncate text-gray-500" title={lastStep.label}>
                                {TYPE_ICON[lastStep.type] ?? '•'} {lastStep.label}
                            </span>
                        )}
                        {activity.length > 0 && (
                            <button
                                type="button"
                                className="ml-auto shrink-0 text-gray-400 hover:text-gray-700"
                                title={showActivity ? 'Verlauf der Schritte ausblenden' : 'Verlauf der Schritte anzeigen'}
                                onClick={() => setShowActivity((v) => !v)}
                            >
                                {showActivity ? '▴' : '▾'}
                            </button>
                        )}
                    </div>

                    {showActivity && (
                        <ol className="mt-1.5 space-y-0.5 border-t pt-1.5">
                            {activity.map((schritt, i) => (
                                <li key={i} className="flex gap-1.5 text-gray-500">
                                    <span className="shrink-0">{TYPE_ICON[schritt.type] ?? '•'}</span>
                                    <span className="min-w-0 [overflow-wrap:anywhere]">{schritt.label}</span>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            )}

            {/* Nothing sideways: the conversation should scroll down, not across.
                Otherwise a single long link widens the whole column. */}
            <div ref={threadEl} onScroll={merkeObAmEnde} className="flex-1 space-y-2 overflow-x-hidden overflow-y-auto p-3">
                {loading && <div className="text-sm text-gray-400">Lädt…</div>}
                {verborgen > 0 && (
                    // Sagen, dass etwas fehlt. Ein stillschweigend gekuerzter
                    // Verlauf sieht aus wie ein Agent, der etwas vergessen hat.
                    <div className="text-center text-xs text-gray-400">
                        {verborgen} ältere {verborgen === 1 ? 'Nachricht' : 'Nachrichten'} nicht angezeigt
                    </div>
                )}
                {!loading && messages.length === 0 && (
                    <div className="text-sm text-gray-400">Noch kein Chat für dieses Postfach (oder AI Brain nicht erreichbar).</div>
                )}
                {sichtbar.map((m) => (
                    <div key={m.id} className={m.role === 'user' ? 'text-right' : 'text-left'}>
                        <div
                            className={[
                                'inline-block max-w-[85%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap',
                                // `whitespace-pre-wrap` breaks at spaces — a long URL has
                                // none and grew out of the bubble. `anywhere` allows the
                                // break mid-word, but only when it otherwise will not fit.
                                '[overflow-wrap:anywhere]',
                                m.role === 'user' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-900',
                            ].join(' ')}
                        >
                            {m.is_pending ? <span className="animate-pulse">…</span> : m.content}
                        </div>
                    </div>
                ))}
            </div>

            <div className="border-t p-2">
                {error && <p className="mb-1 text-xs text-red-500">{error}</p>}
                <form className="flex gap-2" onSubmit={(e) => void send(e)}>
                    <input
                        value={input}
                        disabled={sending}
                        placeholder="Nachricht an den Mailbox-Agenten…"
                        className="flex-1 rounded-md border px-3 py-2 text-sm"
                        onChange={(e) => setInput(e.target.value)}
                    />
                    {controls?.({ insert, sending })}
                    <button
                        type="submit"
                        disabled={sending || !input.trim()}
                        className="rounded-md bg-blue-600 px-3 py-2 text-sm text-white disabled:opacity-50"
                    >
                        Senden
                    </button>
                </form>
            </div>
        </div>
    );
}

/**
 * The microphone button, for products that have speech.
 *
 * The recording itself stays with the product — it is its transcription
 * endpoint and its permission dialog. What the button looks like in which
 * state is not, and a second product would draw it identically.
 */
export function MailboxChatMicButton({
    recording,
    processing,
    onToggle,
}: {
    recording: boolean;
    processing: boolean;
    onToggle: () => void;
}) {
    return (
        <button
            type="button"
            disabled={processing}
            className={`rounded-md border px-3 py-2 text-sm ${recording ? 'text-red-500' : 'text-gray-500 hover:text-gray-800'}`}
            title={recording ? 'Aufnahme stoppen & transkribieren' : 'Per Sprache diktieren'}
            onClick={onToggle}
        >
            {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Mic className={`h-4 w-4 ${recording ? 'animate-pulse' : ''}`} />}
        </button>
    );
}

export default MailboxChat;
