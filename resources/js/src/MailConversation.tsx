import { useState } from 'react';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { Skeleton } from './ui/skeleton';
import { cn } from './ui/utils';

/**
 * Eine Konversation, gelesen wie ein Chat.
 *
 * ## Was hier NICHT passiert
 *
 * Getrennt wird nicht. Zitat, Signatur und Fusszeile hat die Aufbereitung
 * beim Erfassen abgeschnitten; diese Ansicht zeigt nur, was schon getrennt
 * ist. Hier zu rechnen hiesse, bei jedem Blick ein anderes Ergebnis riskieren
 * zu koennen als das, was in der Ablage steht.
 *
 * ## Warum das Abgeschnittene trotzdem mitkommt
 *
 * Die Erkennung ist Heuristik und irrt. Wenn sie daneben greift, fehlt im
 * Verlauf ein Satz — und ein fehlender Satz ist von einem nie geschriebenen
 * nicht zu unterscheiden.
 *
 * Deshalb steht unter jeder Blase, was weggelassen wurde, und ein Klick holt
 * es zurueck. Nicht als Notausgang, sondern als das, was den Verlauf
 * ueberhaupt erst vertretbar macht: Man kann ihm ansehen, was er verschweigt.
 *
 * ## Und der Weg zur echten Mail
 *
 * Der Verlauf ist eine Lesehilfe, kein Ersatz. Wer das Original will — wegen
 * der Formatierung, wegen eines Anhangs, wegen der Kopfzeilen —, ist einen
 * Klick davon entfernt. Eine Nachricht, die nicht mehr im Postfach liegt,
 * sagt das und bietet den Weg nicht an.
 */
export interface ConversationEntry {
    id: number;
    message_id: string;
    from: { email: string | null; name: string | null };
    sent_at: string | null;
    subject: string | null;
    content: string;
    quote: string | null;
    signature: string | null;
    footer: string | null;
    has_attachments: boolean;
    attachment_count: number;
    in_mailbox: boolean;
    folder: string | null;
    uid: number | string | null;
}

export interface MailConversationLabels {
    empty: string;
    loading: string;
    showOmitted: string;
    hideOmitted: string;
    quote: string;
    signature: string;
    footer: string;
    openOriginal: string;
    notInMailbox: string;
    attachments: (count: number) => string;
    /** Wenn die Aufbereitung nichts uebrig liess — siehe unten. */
    nothingWritten: string;
}

export interface MailConversationProps {
    entries: ConversationEntry[];
    labels: MailConversationLabels;
    loading?: boolean;
    /**
     * Die eigenen Adressen des Postfachs.
     *
     * Ohne sie stuenden alle Blasen auf derselben Seite, und ein Verlauf, in
     * dem man die eigenen Beitraege nicht erkennt, ist kein Verlauf.
     */
    ownAddresses?: string[];
    onOpenOriginal?: (entry: ConversationEntry) => void;
    formatDateTime?: (iso: string) => string;
}

export function MailConversation({
    entries,
    labels,
    loading = false,
    ownAddresses = [],
    onOpenOriginal,
    formatDateTime = (iso) => new Date(iso).toLocaleString(),
}: MailConversationProps) {
    if (loading) {
        return (
            <div className="space-y-4 p-4" data-slot="mail-conversation-loading" aria-label={labels.loading}>
                {[0, 1, 2].map((i) => (
                    <Skeleton key={i} className={cn('h-20 w-3/4 rounded-lg', i % 2 === 1 && 'ml-auto')} />
                ))}
            </div>
        );
    }

    if (entries.length === 0) {
        return <p className="p-6 text-center text-sm text-muted-foreground">{labels.empty}</p>;
    }

    const eigene = new Set(ownAddresses.map((a) => a.toLowerCase()));

    return (
        <div className="space-y-4 p-4" data-slot="mail-conversation">
            {entries.map((eintrag) => (
                <Blase
                    key={eintrag.id}
                    eintrag={eintrag}
                    vonUns={eigene.has((eintrag.from.email ?? '').toLowerCase())}
                    labels={labels}
                    onOpenOriginal={onOpenOriginal}
                    formatDateTime={formatDateTime}
                />
            ))}
        </div>
    );
}

function Blase({
    eintrag,
    vonUns,
    labels,
    onOpenOriginal,
    formatDateTime,
}: {
    eintrag: ConversationEntry;
    vonUns: boolean;
    labels: MailConversationLabels;
    onOpenOriginal?: (entry: ConversationEntry) => void;
    formatDateTime: (iso: string) => string;
}) {
    const [offen, setOffen] = useState(false);

    const weggelassen = [
        eintrag.quote ? { titel: labels.quote, text: eintrag.quote } : null,
        eintrag.signature ? { titel: labels.signature, text: eintrag.signature } : null,
        eintrag.footer ? { titel: labels.footer, text: eintrag.footer } : null,
    ].filter((t): t is { titel: string; text: string } => t !== null);

    return (
        <div className={cn('flex', vonUns && 'justify-end')} data-slot="mail-conversation-entry">
            <div className={cn('max-w-[85%] rounded-lg border p-3', vonUns ? 'bg-primary/5' : 'bg-muted/40')}>
                <div className="mb-1 flex flex-wrap items-baseline gap-x-2 gap-y-1 text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">{eintrag.from.name || eintrag.from.email || '—'}</span>
                    {eintrag.sent_at && <span>{formatDateTime(eintrag.sent_at)}</span>}
                    {eintrag.has_attachments && (
                        <Badge variant="secondary" className="text-[10px]">
                            {labels.attachments(eintrag.attachment_count)}
                        </Badge>
                    )}
                </div>

                {/*
                 * Eine Blase ohne Text ist kein Fehler: Es gibt Antworten, die
                 * nur aus einem Anhang bestehen. Sie leer zu zeigen saehe
                 * dagegen nach einem aus.
                 */}
                {eintrag.content.trim() !== '' ? (
                    <p className="whitespace-pre-wrap text-sm">{eintrag.content}</p>
                ) : (
                    <p className="text-sm italic text-muted-foreground">{labels.nothingWritten}</p>
                )}

                {(weggelassen.length > 0 || onOpenOriginal) && (
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        {weggelassen.length > 0 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-6 px-1.5 text-xs text-muted-foreground"
                                onClick={() => setOffen(!offen)}
                            >
                                {offen ? labels.hideOmitted : labels.showOmitted}
                            </Button>
                        )}

                        {onOpenOriginal &&
                            (eintrag.in_mailbox ? (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-6 px-1.5 text-xs text-muted-foreground"
                                    onClick={() => onOpenOriginal(eintrag)}
                                >
                                    {labels.openOriginal}
                                </Button>
                            ) : (
                                // Kein toter Knopf: Die Nachricht liegt nicht
                                // mehr im Postfach, das Original ist von hier
                                // aus nicht zu oeffnen. Das zu sagen ist
                                // besser, als einen Klick ins Leere anzubieten.
                                <span className="text-xs text-muted-foreground">{labels.notInMailbox}</span>
                            ))}
                    </div>
                )}

                {offen && (
                    <div className="mt-2 space-y-2 border-t pt-2" data-slot="mail-conversation-omitted">
                        {weggelassen.map((teil) => (
                            <div key={teil.titel}>
                                <p className="text-[10px] font-medium uppercase text-muted-foreground">{teil.titel}</p>
                                <p className="whitespace-pre-wrap text-xs text-muted-foreground">{teil.text}</p>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
