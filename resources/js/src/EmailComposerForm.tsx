import { Paperclip, PenLine, Save, Send, X } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ChangeEvent,
    type ComponentType,
    type DragEvent,
    type ReactNode,
} from 'react';
import EmailBodyViewer, { type EmailBodyViewerLabels } from './EmailBodyViewer';
import { buildBodyWithSignature, stripSignature, swapSignature } from './signature';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { Input } from './ui/input';
import { Label } from './ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';

/**
 * The one form behind every way of writing a mail: new, reply, forward, draft.
 *
 * ## Everything that talks to a product comes in as a prop
 *
 * Three things a mail form needs are decisions the product has already made,
 * and none of them belong here:
 *
 * - the **rich-text editor** (`editor`) — one product uses Tiptap, the next may
 *   not, and dragging an editor into a shared package makes it everyone's
 *   editor
 * - the **recipient suggestions** (`recipients`) — they come from endpoints
 *   that only exist in the product
 * - the **signatures** (`signatures`) — a table of the product, behind its own
 *   endpoint
 *
 * What stays here is the form itself and the behaviour that is the same
 * everywhere: chips for addresses, the debounced directory lookup with its
 * abort handling, attachments by drag and drop, the signature block, and the
 * preview of what the recipient will see.
 *
 * ## No words of its own
 *
 * Every piece of text comes in through `labels`. A mail form is almost nothing
 * but labels, so a package that brought its own would be a package that brings
 * its own language.
 *
 * ## One oddity worth knowing
 *
 * "No signature" needs a real value in a Radix select — an empty string is
 * forbidden there. Hence `NO_SIGNATURE` as a placeholder that is translated
 * back at the boundary.
 */

export interface EmailAddress {
    email: string;
    name: string;
}

interface RecipientSuggestion extends EmailAddress {
    source?: string;
    company?: string | null;
}

export interface ComposerAccount {
    id: number;
    name: string;
    email: string;
}

type Field = 'to' | 'cc' | 'bcc';

/** Placeholder for "no signature" — Radix forbids an empty value. */
const NO_SIGNATURE = '__none__';

export interface EmailSignatureOption {
    id: number;
    name: string;
    body_html: string;
    is_default?: boolean;
}

/**
 * Where recipient suggestions come from.
 *
 * Both calls get an AbortSignal and are expected to honour it: the search runs
 * while somebody types, and a late answer must not overwrite a newer one.
 */
export interface RecipientSource {
    /** The everyday suggestions — loaded once. */
    all(signal: AbortSignal): Promise<RecipientSuggestion[]>;
    /** The wider directory — asked while typing, debounced by the form. */
    search(query: string, signal: AbortSignal): Promise<RecipientSuggestion[]>;
}

/** The signatures of the product, and how to (re)load them for an account. */
export interface SignatureSource {
    signatures: EmailSignatureOption[];
    load(accountId?: number): Promise<unknown>;
    /** The signature to preselect, or null. */
    defaultSignature(): EmailSignatureOption | null;
}

/** Everything this form says out loud. */
export interface EmailComposerFormLabels {
    to: string;
    cc: string;
    bcc: string;
    subject: string;
    subjectPlaceholder: string;
    message: string;
    preview: string;
    attachments: string;
    signature: string;
    noSignature: string;
    /** Marks the preselected signature, e.g. " (default)". */
    defaultSuffix: string;
    /** Shown in the preview while nothing has been written yet. */
    previewEmpty: string;
    /** The overlay while files are dragged across the form. */
    dropFiles: string;
    addFile: string;
    chooseAccount: string;
    send: string;
    sending: string;
    saveDraft: string;
    savingDraft: string;
    toPlaceholder: string;
    ccPlaceholder: string;
    bccPlaceholder: string;
    /** Where a suggestion comes from: recent, colleague, and the product's own sources. */
    source: (key: string) => string;
    body: EmailBodyViewerLabels;
}

export interface EmailComposerFormProps {
    labels: EmailComposerFormLabels;
    /** The product's rich-text editor. */
    editor: ComponentType<{ value: string; onChange: (value: string) => void }>;
    recipients: RecipientSource;
    signatureSource: SignatureSource;

    to: EmailAddress[];
    onToChange: (value: EmailAddress[]) => void;
    cc?: EmailAddress[];
    onCcChange?: (value: EmailAddress[]) => void;
    bcc?: EmailAddress[];
    onBccChange?: (value: EmailAddress[]) => void;
    subject: string;
    onSubjectChange: (value: string) => void;
    bodyHtml: string;
    onBodyHtmlChange: (value: string) => void;
    accountId?: number | null;
    onAccountIdChange?: (value: number | null) => void;
    attachments?: File[];
    onAttachmentsChange?: (value: File[]) => void;

    accounts?: ComposerAccount[];
    signatureAccountId?: number | null;
    allowBcc?: boolean;
    allowAttachments?: boolean;
    sending?: boolean;
    submitLabel?: string;
    errors?: Record<string, string>;
    errorMessage?: string | null;
    allowDraft?: boolean;
    draftSaving?: boolean;
    applySignature?: boolean;

    onSend: () => void;
    onCancel: () => void;
    onSaveDraft?: () => void;

    /** Ersetzt den `header`-Slot der Vue-Fassung. */
    header?: ReactNode;
    /** Ersetzt den `quoted`-Slot der Vue-Fassung (zitiertes Original). */
    quoted?: ReactNode;
}

function formatDisplay(addr: EmailAddress): string {
    return addr.name ? `${addr.name} <${addr.email}>` : addr.email;
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function sourceVariant(source?: string): 'default' | 'secondary' | 'outline' {
    switch (source) {
        case 'verwaltung':
        case 'crm':
            return 'default';
        case 'colleague':
            return 'secondary';
        default:
            return 'outline';
    }
}

interface RecipientFieldProps {
    label: string;
    placeholder: string;
    recipients: EmailAddress[];
    onRecipientsChange: (value: EmailAddress[]) => void;
    inputValue: string;
    onInputChange: (value: string) => void;
    active: boolean;
    onFocus: () => void;
    /** Enter: take the input, keep the field active. */
    onCommit: () => void;
    onFieldBlur: () => void;
    suggestions: RecipientSuggestion[];
    onPick: (recipient: RecipientSuggestion) => void;
    error?: string;
    /** Next to the label (the cc/bcc switches). */
    trailing?: ReactNode;
    /** Names the origin of a suggestion — the product knows its own sources. */
    sourceLabel: (key: string) => string;
}

/**
 * One recipient field with its chips and suggestion list. The same block used
 * to stand three times, word for word.
 */
function RecipientField({
    label,
    placeholder,
    recipients,
    onRecipientsChange,
    inputValue,
    onInputChange,
    active,
    onFocus,
    onCommit,
    onFieldBlur,
    suggestions,
    onPick,
    error,
    trailing,
    sourceLabel,
}: RecipientFieldProps) {
    const remove = (index: number) => {
        onRecipientsChange(recipients.filter((_, i) => i !== index));
    };

    return (
        <div>
            <div className="flex items-center justify-between">
                <Label className="text-xs text-muted-foreground">{label}</Label>
                {trailing}
            </div>
            <div className="relative mt-1">
                <div className="flex flex-wrap items-center gap-1 rounded-md border bg-background p-2">
                    {recipients.map((addr, i) => (
                        <Badge key={addr.email} variant="secondary" className="gap-1">
                            {formatDisplay(addr)}
                            <button type="button" className="ml-1 rounded-full p-0.5 hover:bg-muted-foreground/20" onClick={() => remove(i)}>
                                <X className="h-3 w-3" />
                            </button>
                        </Badge>
                    ))}
                    <Input
                        type="email"
                        value={inputValue}
                        placeholder={placeholder}
                        className="h-7 min-w-[200px] flex-1 border-0 px-2 text-sm shadow-none focus-visible:ring-0"
                        onChange={(e) => onInputChange(e.target.value)}
                        onFocus={onFocus}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                onCommit();
                            }
                        }}
                        onBlur={onFieldBlur}
                    />
                </div>
                {active && suggestions.length > 0 && (
                    <div className="absolute z-50 mt-1 max-h-64 w-full overflow-auto rounded-md border bg-popover shadow-md">
                        {suggestions.map((r) => (
                            <button
                                key={r.email}
                                type="button"
                                className="flex w-full flex-col items-start px-3 py-1.5 text-left hover:bg-muted"
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    onPick(r);
                                }}
                            >
                                <span className="flex w-full items-center gap-2">
                                    {r.name && <span className="text-sm font-medium">{r.name}</span>}
                                    <Badge variant={sourceVariant(r.source)} className="ml-auto shrink-0 text-[10px]">
                                        {sourceLabel(r.source ?? '')}
                                    </Badge>
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {r.email}
                                    {r.company ? ` · ${r.company}` : ''}
                                </span>
                            </button>
                        ))}
                    </div>
                )}
            </div>
            {error && <p className="mt-1 text-xs text-destructive">{error}</p>}
        </div>
    );
}

export function EmailComposerForm({
    labels,
    editor: Editor,
    recipients,
    signatureSource,
    to,
    onToChange,
    cc = [],
    onCcChange,
    bcc = [],
    onBccChange,
    subject,
    onSubjectChange,
    bodyHtml,
    onBodyHtmlChange,
    accountId = null,
    onAccountIdChange,
    attachments = [],
    onAttachmentsChange,
    accounts = [],
    signatureAccountId = null,
    allowBcc = false,
    allowAttachments = false,
    sending = false,
    submitLabel,
    errors = {},
    errorMessage = null,
    allowDraft = false,
    draftSaving = false,
    applySignature = true,
    onSend,
    onCancel,
    onSaveDraft,
    header,
    quoted,
}: EmailComposerFormProps) {
    const [newTo, setNewTo] = useState('');
    const [newCc, setNewCc] = useState('');
    const [newBcc, setNewBcc] = useState('');
    const [showCc, setShowCc] = useState(false);
    const [showBcc, setShowBcc] = useState(false);
    const [isDragging, setIsDragging] = useState(false);
    const fileInput = useRef<HTMLInputElement | null>(null);

    const ccVisible = showCc || cc.length > 0;
    const bccVisible = allowBcc && (showBcc || bcc.length > 0);
    const canSend = to.length > 0 && !sending;

    // --- Signaturen -----------------------------------------------------
    const { signatures, load: loadSignatures, defaultSignature } = signatureSource;
    const [selectedSignatureId, setSelectedSignatureId] = useState<number | null>(null);
    const [loadedFor, setGeladenFuer] = useState<string | null>(null);
    const angewendetFuerRef = useRef<string | null>(null);

    const bodyHtmlRef = useRef(bodyHtml);
    const applySignatureRef = useRef(applySignature);
    const activeFieldRef = useRef<Field | null>(null);

    /*
     * Spiegel der jeweils juengsten Werte. Bewusst OHNE Abhaengigkeitsliste und
     * als ERSTER Effekt der Komponente: Effekte laufen in der Reihenfolge ihrer
     * Deklaration, damit sehen die folgenden Effekte im selben Durchlauf schon
     * den aktuellen Stand.
     */
    useEffect(() => {
        bodyHtmlRef.current = bodyHtml;
        applySignatureRef.current = applySignature;
    });

    useEffect(() => {
        let abgebrochen = false;
        const schluessel = String(signatureAccountId ?? 'all');

        void loadSignatures(signatureAccountId ?? undefined).then(() => {
            if (!abgebrochen) {
                setGeladenFuer(schluessel);
            }
        });

        return () => {
            abgebrochen = true;
        };
    }, [signatureAccountId, loadSignatures]);

    /*
     * Die Vorgabe-Signatur wird in einem EIGENEN Effekt gesetzt, nicht direkt
     * nach `load()`: `defaultSignature()` liest den Zustand des Hakens, und der
     * ist im selben Durchlauf noch der alte. Der Merker sorgt dafuer, dass das
     * je Konto genau einmal passiert — sonst wuerde ein neu erzeugter
     * `onBodyHtmlChange`-Rueckruf des Aufrufers den geschriebenen Text
     * ueberbuegeln.
     */
    useEffect(() => {
        if (loadedFor === null || angewendetFuerRef.current === loadedFor) {
            return;
        }
        angewendetFuerRef.current = loadedFor;

        // Beim Fortsetzen eines Entwurfs bleibt der Text unangetastet; die
        // Liste wird trotzdem geladen, damit das Auswahlfeld gefuellt ist.
        if (!applySignatureRef.current) {
            return;
        }

        const def = defaultSignature();
        if (def) {
            setSelectedSignatureId(def.id);
            onBodyHtmlChange(buildBodyWithSignature(def.body_html));
        } else {
            setSelectedSignatureId(null);
            onBodyHtmlChange(stripSignature(bodyHtmlRef.current));
        }
    }, [loadedFor, defaultSignature, onBodyHtmlChange]);

    const onSignatureChange = (value: string) => {
        if (value === NO_SIGNATURE) {
            onBodyHtmlChange(stripSignature(bodyHtmlRef.current));
            setSelectedSignatureId(null);
            return;
        }
        const id = Number(value);
        const sig = signatures.find((s) => s.id === id);
        if (!sig) {
            return;
        }
        setSelectedSignatureId(id);
        onBodyHtmlChange(swapSignature(bodyHtmlRef.current, sig.body_html));
    };

    // --- Empfaenger -----------------------------------------------------
    const lists: Record<Field, EmailAddress[]> = useMemo(() => ({ to, cc, bcc }), [to, cc, bcc]);

    const setList = useCallback(
        (field: Field, value: EmailAddress[]) => {
            if (field === 'to') {
                onToChange(value);
            } else if (field === 'cc') {
                onCcChange?.(value);
            } else {
                onBccChange?.(value);
            }
        },
        [onToChange, onCcChange, onBccChange],
    );

    const inputs: Record<Field, string> = useMemo(() => ({ to: newTo, cc: newCc, bcc: newBcc }), [newTo, newCc, newBcc]);

    const setInput = useCallback((field: Field, value: string) => {
        if (field === 'to') {
            setNewTo(value);
        } else if (field === 'cc') {
            setNewCc(value);
        } else {
            setNewBcc(value);
        }
    }, []);

    const addRecipient = useCallback(
        (field: Field) => {
            const email = inputs[field].trim();
            const liste = lists[field];
            if (email && !liste.some((r) => r.email.toLowerCase() === email.toLowerCase())) {
                setList(field, [...liste, { email, name: '' }]);
            }
            setInput(field, '');
        },
        [inputs, lists, setList, setInput],
    );

    // --- Vervollstaendigung ---------------------------------------------
    // Der lokale Vorrat (Kollegen, letzte Empfaenger, Verlauf) wird einmal
    // geladen und sofort im Browser gefiltert. Verzeichnisse ausserhalb
    // The wider directory is asked for with a delay and appended below.
    const [recipientPool, setRecipientPool] = useState<RecipientSuggestion[]>([]);
    const [externalPool, setExternalPool] = useState<RecipientSuggestion[]>([]);
    const [activeField, setActiveField] = useState<Field | null>(null);

    useEffect(() => {
        const abort = new AbortController();

        void (async () => {
            try {
                setRecipientPool(await recipients.all(abort.signal));
            } catch {
                // No suggestions — addresses can still be typed.
            }
        })();

        return () => {
            abort.abort();
        };
    }, []);

    /*
     * Verzoegerte Abfrage des externen Verzeichnisses. Zeitgeber UND Anfrage
     * haengen in der Aufraeumfunktion: sonst schreibt eine spaet eintreffende
     * Antwort noch in eine laengst ausgehaengte Komponente.
     */
    useEffect(() => {
        const field = activeFieldRef.current;
        const q = (field ? inputs[field] : '').trim();

        if (q.length < 2) {
            setExternalPool([]);
            return;
        }

        const abort = new AbortController();
        const timer = setTimeout(async () => {
            try {
                setExternalPool(await recipients.search(q, abort.signal));
            } catch {
                if (!abort.signal.aborted) {
                    setExternalPool([]);
                }
            }
        }, 300);

        return () => {
            clearTimeout(timer);
            abort.abort();
        };
        // `activeFieldRef` steht bewusst nicht in der Liste: ein blosser
        // Feldwechsel ohne Tastendruck loeste in der Vue-Fassung ebenfalls
        // keine Abfrage aus.
    }, [inputs]);

    const suggestionsFor = useCallback(
        (field: Field): RecipientSuggestion[] => {
            const query = inputs[field].trim().toLowerCase();
            if (query.length < 1) {
                return [];
            }
            const vorhanden = new Set(lists[field].map((r) => r.email.toLowerCase()));
            const passt = (r: RecipientSuggestion) => r.email.toLowerCase().includes(query) || (r.name ?? '').toLowerCase().includes(query);

            const lokal = recipientPool
                .filter((r) => !vorhanden.has(r.email.toLowerCase()))
                .filter(passt)
                .slice(0, 6);

            const lokaleAdressen = new Set(lokal.map((r) => r.email.toLowerCase()));
            const extern = externalPool
                .filter((r) => !vorhanden.has(r.email.toLowerCase()) && !lokaleAdressen.has(r.email.toLowerCase()))
                .slice(0, 6);

            return [...lokal, ...extern];
        },
        [inputs, lists, recipientPool, externalPool],
    );

    const pickSuggestion = useCallback(
        (field: Field, recipient: EmailAddress) => {
            const liste = lists[field];
            if (!liste.some((r) => r.email.toLowerCase() === recipient.email.toLowerCase())) {
                setList(field, [...liste, { email: recipient.email, name: recipient.name ?? '' }]);
            }
            setInput(field, '');
            // Das Field bleibt fokussiert (der Mausdruck wird unterdrueckt), also
            // bleibt es auch „aktiv" — sonst zeigt die Vervollstaendigung fuer den
            // naechsten Empfaenger erst nach erneutem Hineinklicken wieder etwas.
            // The list disappears anyway, because the input is empty now.
            setActiveField(field);
        },
        [lists, setList, setInput],
    );

    const onRecipientBlur = useCallback(
        (field: Field) => {
            addRecipient(field);
            setActiveField(null);
        },
        [addRecipient],
    );

    // --- Anhaenge -------------------------------------------------------
    const onFilesPicked = (event: ChangeEvent<HTMLInputElement>) => {
        const felder = event.target.files;
        if (!felder) {
            return;
        }
        onAttachmentsChange?.([...attachments, ...Array.from(felder)]);
        event.target.value = '';
    };

    const removeAttachment = (index: number) => {
        onAttachmentsChange?.(attachments.filter((_, i) => i !== index));
    };

    const onDragOver = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        if (allowAttachments) {
            setIsDragging(true);
        }
    };

    const onDragLeave = (event: DragEvent<HTMLDivElement>) => {
        // Nur zuruecksetzen, wenn die Ablagezone ganz verlassen wird — nicht
        // beim Ueberqueren von Kindelementen.
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
            setIsDragging(false);
        }
    };

    const onDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setIsDragging(false);
        if (!allowAttachments) {
            return;
        }
        // Was im Editor abgelegt wird, behandelt dieser selbst (eingebettete
        // Bilder) — sonst haengt es doppelt.
        if ((event.target as HTMLElement)?.closest?.('.tiptap-email-editor')) {
            return;
        }
        const dateien = Array.from(event.dataTransfer?.files ?? []);
        if (dateien.length > 0) {
            onAttachmentsChange?.([...attachments, ...dateien]);
        }
    };

    return (
        <div className="relative space-y-4" onDragOver={onDragOver} onDragLeave={onDragLeave} onDrop={onDrop}>
            {isDragging && allowAttachments && (
                <div className="pointer-events-none absolute inset-0 z-50 flex items-center justify-center rounded-md border-2 border-dashed border-primary bg-primary/10 text-sm font-medium text-primary">
                    {labels.dropFiles}
                </div>
            )}
            {header}

            {accounts.length > 0 && (
                <div>
                    <Label className="text-xs text-muted-foreground">Von</Label>
                    <Select
                        value={accountId === null ? undefined : String(accountId)}
                        onValueChange={(v) => onAccountIdChange?.(v ? Number(v) : null)}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder={labels.chooseAccount} />
                        </SelectTrigger>
                        <SelectContent>
                            {accounts.map((account) => (
                                <SelectItem key={account.id} value={String(account.id)}>
                                    {account.name} &lt;{account.email}&gt;
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {errors.email_account_id && <p className="mt-1 text-xs text-destructive">{errors.email_account_id}</p>}
                </div>
            )}

            <RecipientField
                sourceLabel={labels.source}
                label="An"
                placeholder={labels.toPlaceholder}
                recipients={to}
                onRecipientsChange={(v) => setList('to', v)}
                inputValue={newTo}
                onInputChange={setNewTo}
                active={activeField === 'to'}
                onFocus={() => setActiveField('to')}
                onCommit={() => addRecipient('to')}
                onFieldBlur={() => onRecipientBlur('to')}
                suggestions={suggestionsFor('to')}
                onPick={(r) => pickSuggestion('to', r)}
                error={errors.to}
                trailing={
                    <div className="flex gap-2 text-xs">
                        {!ccVisible && (
                            <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => setShowCc(true)}>
                                CC
                            </button>
                        )}
                        {allowBcc && !bccVisible && (
                            <button type="button" className="text-muted-foreground hover:text-foreground" onClick={() => setShowBcc(true)}>
                                BCC
                            </button>
                        )}
                    </div>
                }
            />

            {ccVisible && (
                <RecipientField
                sourceLabel={labels.source}
                    label="CC"
                    placeholder={labels.ccPlaceholder}
                    recipients={cc}
                    onRecipientsChange={(v) => setList('cc', v)}
                    inputValue={newCc}
                    onInputChange={setNewCc}
                    active={activeField === 'cc'}
                    onFocus={() => setActiveField('cc')}
                    onCommit={() => addRecipient('cc')}
                    onFieldBlur={() => onRecipientBlur('cc')}
                    suggestions={suggestionsFor('cc')}
                    onPick={(r) => pickSuggestion('cc', r)}
                />
            )}

            {bccVisible && (
                <RecipientField
                sourceLabel={labels.source}
                    label="BCC"
                    placeholder={labels.bccPlaceholder}
                    recipients={bcc}
                    onRecipientsChange={(v) => setList('bcc', v)}
                    inputValue={newBcc}
                    onInputChange={setNewBcc}
                    active={activeField === 'bcc'}
                    onFocus={() => setActiveField('bcc')}
                    onCommit={() => addRecipient('bcc')}
                    onFieldBlur={() => onRecipientBlur('bcc')}
                    suggestions={suggestionsFor('bcc')}
                    onPick={(r) => pickSuggestion('bcc', r)}
                />
            )}

            <div>
                <Label className="text-xs text-muted-foreground">{labels.subject}</Label>
                <Input value={subject} className="mt-1" placeholder={labels.subjectPlaceholder} onChange={(e) => onSubjectChange(e.target.value)} />
                {errors.subject && <p className="mt-1 text-xs text-destructive">{errors.subject}</p>}
            </div>

            {signatures.length > 0 && (
                <div className="flex items-center gap-2">
                    <PenLine className="h-4 w-4 text-muted-foreground" />
                    <Label className="text-xs text-muted-foreground">{labels.signature}</Label>
                    <Select value={selectedSignatureId === null ? NO_SIGNATURE : String(selectedSignatureId)} onValueChange={onSignatureChange}>
                        <SelectTrigger className="h-8 w-auto min-w-[180px] text-xs">
                            <SelectValue placeholder={labels.noSignature} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NO_SIGNATURE}>{labels.noSignature}</SelectItem>
                            {signatures.map((sig) => (
                                <SelectItem key={sig.id} value={String(sig.id)}>
                                    {sig.name}
                                    {sig.is_default ? labels.defaultSuffix : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                <div>
                    <Label className="mb-1 block text-xs text-muted-foreground">{labels.message}</Label>
                    <Editor value={bodyHtml} onChange={onBodyHtmlChange} />
                    {errors.body_html && <p className="mt-1 text-xs text-destructive">{errors.body_html}</p>}
                </div>
                <div>
                    <Label className="mb-1 block text-xs text-muted-foreground">{labels.preview}</Label>
                    <div className="h-[460px] overflow-hidden rounded-md border">
                        <EmailBodyViewer bodyHtml={bodyHtml} labels={{ ...labels.body, empty: labels.previewEmpty }} />
                    </div>
                </div>
            </div>

            {allowAttachments && (
                <div>
                    <div className="flex items-center justify-between">
                        <Label className="text-xs text-muted-foreground">{labels.attachments}</Label>
                        <Button type="button" variant="ghost" size="sm" onClick={() => fileInput.current?.click()}>
                            <Paperclip className="mr-1 h-3 w-3" />
                            {labels.addFile}
                        </Button>
                        <input ref={fileInput} type="file" multiple className="hidden" onChange={onFilesPicked} />
                    </div>
                    {attachments.length > 0 && (
                        <div className="mt-2 space-y-1">
                            {attachments.map((file, i) => (
                                <div
                                    key={`${file.name}-${i}`}
                                    className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-1.5 text-sm"
                                >
                                    <span className="truncate">
                                        {file.name} <span className="text-xs text-muted-foreground">({formatBytes(file.size)})</span>
                                    </span>
                                    <button
                                        type="button"
                                        className="ml-2 rounded-full p-1 hover:bg-muted-foreground/20"
                                        onClick={() => removeAttachment(i)}
                                    >
                                        <X className="h-3 w-3" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                    {errors.attachments && <p className="mt-1 text-xs text-destructive">{errors.attachments}</p>}
                </div>
            )}

            {errorMessage && (
                <div className="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">{errorMessage}</div>
            )}

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" disabled={sending} onClick={onCancel}>
                    Abbrechen
                </Button>
                {allowDraft && (
                    <Button type="button" variant="ghost" disabled={draftSaving || sending} onClick={onSaveDraft}>
                        <Save className="mr-2 h-4 w-4" />
                        {draftSaving ? labels.savingDraft : labels.saveDraft}
                    </Button>
                )}
                <Button type="button" disabled={!canSend} onClick={onSend}>
                    <Send className="mr-2 h-4 w-4" />
                    {sending ? labels.sending : (submitLabel ?? labels.send)}
                </Button>
            </div>

            {quoted}
        </div>
    );
}

export default EmailComposerForm;
