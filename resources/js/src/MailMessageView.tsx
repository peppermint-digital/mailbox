import { Archive, Download, Forward, Loader2, Mail, MailOpen, Paperclip, Pencil, Reply, Star, Trash2, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import EmailBodyViewer, { type EmailBodyViewerLabels } from './EmailBodyViewer';
import { Button } from './ui/button';
import { cn } from './ui/utils';
import type { MessageHandle } from './rows';

/**
 * The reading pane: what one opened message shows and what may be done to it.
 *
 * ## The two states that are not "a message"
 *
 * Loading and "nothing selected" are part of this view, not of the product
 * around it — otherwise every product invents its own empty state and they
 * drift.
 *
 * ## An archived message is not a mailbox message
 *
 * Replying, moving, flagging and deleting all need an IMAP uid. A message that
 * only exists in the application's archive has none, so it gets a badge
 * instead of a toolbar. Offering buttons that cannot work is worse than
 * offering none.
 *
 * ## Reply-all appears only when there is an "all"
 *
 * With a single recipient and no CC, replying to all is replying — a button
 * that claims otherwise is a lie about what will happen.
 *
 * ## What the product brings
 *
 * Every word (`labels`), both formatters, the attachment URL, and its own
 * actions: `headerAccessory` for what it hangs on a message (an assignee, a
 * linked contact) and `extraActions` for its own buttons ("link to task").
 */

export interface MailMessageViewAddress {
    email: string;
    name: string;
}

export interface MailMessageViewAttachment {
    index: number;
    id?: number;
    filename: string;
    mime_type: string;
    size: number;
}

export interface MailMessageViewMessage {
    uid: MessageHandle | null;
    message_id: string;
    subject: string;
    from_address: string;
    from_name: string;
    to: MailMessageViewAddress[];
    cc: MailMessageViewAddress[];
    date: string;
    body_html: string | null;
    body_text: string | null;
    attachments: MailMessageViewAttachment[];
    is_read: boolean;
    is_flagged: boolean;
    source?: 'mailbox' | 'stored';
}

export interface MailMessageViewLabels {
    /** Shown while a message is being fetched is a spinner; this is for "none picked". */
    empty: string;
    /** Badge instead of the toolbar for an archived message. */
    archived: string;
    /** Title of that badge — say why there are no buttons. */
    archivedTitle: string;
    editDraft: string;
    reply: string;
    replyAll: string;
    forward: string;
    markRead: string;
    markUnread: string;
    flag: string;
    unflag: string;
    archive: string;
    /** Title of archive when it moves a whole conversation. */
    archiveConversation: (count: number) => string;
    delete: string;
    from: string;
    date: string;
    to: string;
    cc: string;
    downloadAttachment: (filename: string) => string;
    body: EmailBodyViewerLabels;
}

export interface MailMessageViewProps {
    message: MailMessageViewMessage | null;
    labels: MailMessageViewLabels;
    loading?: boolean;
    /** The folder the message sits in — a draft may be edited further. */
    isDraft?: boolean;
    /** How many messages archiving would move; 1 or less means just this one. */
    archiveCount?: number;
    busy?: { archiving?: boolean; deleting?: boolean; moving?: boolean };
    formatDateTime: (iso: string) => string;
    formatAddressList: (addresses: MailMessageViewAddress[]) => string;
    formatFileSize: (bytes: number) => string;
    attachmentUrl: (attachment: MailMessageViewAttachment) => string;
    onReply?: (all: boolean) => void;
    onForward?: () => void;
    onEditDraft?: () => void;
    onToggleRead?: () => void;
    onToggleFlag?: () => void;
    onArchive?: () => void;
    onDelete?: () => void;
    /** What the product hangs on the message — assignee, linked contact. */
    headerAccessory?: ReactNode;
    /** The product's own buttons, e.g. "link to task". Placed before the generic ones. */
    extraActions?: ReactNode;
    /** The folder picker and anything else the product puts in the toolbar. */
    moveControl?: ReactNode;
}

export function MailMessageView({
    message,
    labels,
    loading = false,
    isDraft = false,
    archiveCount = 1,
    busy,
    formatDateTime,
    formatAddressList,
    formatFileSize,
    attachmentUrl,
    onReply,
    onForward,
    onEditDraft,
    onToggleRead,
    onToggleFlag,
    onArchive,
    onDelete,
    headerAccessory,
    extraActions,
    moveControl,
}: MailMessageViewProps) {
    if (loading) {
        return (
            <div className="flex flex-1 items-center justify-center" data-slot="mail-message-loading">
                <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
        );
    }

    if (!message) {
        return (
            <div className="flex flex-1 items-center justify-center text-muted-foreground" data-slot="mail-message-empty">
                <div className="text-center">
                    <Mail className="mx-auto mb-4 h-12 w-12 opacity-30" />
                    <p>{labels.empty}</p>
                </div>
            </div>
        );
    }

    const isArchived = message.source === 'stored';
    const hasMoreRecipients = message.cc.length > 0 || message.to.length > 1;

    return (
        <div className="flex flex-1 flex-col overflow-hidden" data-slot="mail-message-view">
            <div className="space-y-3 border-b p-4">
                {/*
                    Unter `sm` stehen Betreff und Knopfleiste UNTEREINANDER, ab
                    `sm` nebeneinander wie gehabt.

                    ## Zwei Versuche, und warum der erste nicht reichte

                    Hier stand bis zum 30.10.2026 nur `min-w-0` an der linken
                    Spalte. Daneben sitzt eine Knopfleiste mit `shrink-0`, darum
                    herum `justify-between` — auf einem Telefon schrumpfte die
                    Spalte dadurch auf NULL, und der Betreff brach zeichenweise
                    um („N / Partneranfrage: / CRUDE / HARZ …").

                    Der erste Versuch (v0.176.1) setzte `flex-1` daneben. Am
                    Geraet nachgemessen von der Session `mail-rollout`, am
                    30.10.2026 auf brain.proxy.peppermint-digital.com mit dem
                    frisch gebauten Buendel, iPhone-14-Emulation (innerWidth
                    390, DPR 3) — und zwar an der KLASSENLISTE des lebenden
                    Elements, nicht im Quelltext:

                        Betreff-Spalte   BREITE   0   (HOEHE 196)
                        Knopfleiste      BREITE 490
                        Container        BREITE 358

                    `flex-1` stand da und konnte nichts ausrichten: Die Leiste
                    ist mit 490 px breiter als der Container mit 358 px. Es gibt
                    keinen Rest zu verteilen, und `shrink-0` verbietet der
                    Leiste, schmaler zu werden. Die Spalte bekommt deshalb 0 —
                    mit `flex-1` genauso wie ohne.

                    Die Ursache liegt also eine Ebene hoeher, als sie aussah:
                    Nicht die Betreff-Spalte schrumpft zu Unrecht, sondern die
                    Leiste passt bei 390 px nicht in die Zeile. Solange sie dort
                    steht und nicht schrumpfen darf, ist JEDE Breite fuer den
                    Betreff geborgt.

                    Deshalb: eigene Zeile unter `sm`, und die Knoepfe duerfen
                    darin umbrechen. `shrink` zu erlauben waere die naheliegende
                    Alternative und die falsche — dann stauchen die Knoepfe.

                    ## Warum das der jsdom-Test nicht zeigt

                    Er kann nur festhalten, dass die Klassen dastehen. Das taten
                    sie beim ersten Versuch ja auch. Die drei Zahlen oben sind
                    der Nachweis, den jsdom nicht liefert — sie gehoeren
                    deshalb hierher und nicht in eine Zusicherung.
                */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                    <div className="min-w-0 flex-1 space-y-1">
                        <h2 className="text-lg font-semibold">{message.subject}</h2>
                        {headerAccessory}
                    </div>

                    {isArchived ? (
                        <div className="flex flex-wrap items-center gap-2 sm:shrink-0 sm:flex-nowrap" data-slot="mail-message-archived">
                            <span className="rounded bg-muted px-2 py-1 text-xs text-muted-foreground" title={labels.archivedTitle}>
                                {labels.archived}
                            </span>
                        </div>
                    ) : (
                        <div className="flex flex-wrap items-center gap-2 sm:shrink-0 sm:flex-nowrap" data-slot="mail-message-actions">
                            {isDraft && onEditDraft && (
                                <Button onClick={onEditDraft} size="sm" title={labels.editDraft}>
                                    <Pencil className="mr-2 h-4 w-4" />
                                    {labels.editDraft}
                                </Button>
                            )}

                            {/* Nur was einen Rueckruf hat. Bis zum 22.09.2026 standen
                                Antworten und Weiterleiten hier immer — auch in
                                Produkten ohne Sendeweg, wo sie beim Klick nichts
                                taten. Ein Knopf ohne Wirkung sieht aus wie ein
                                Defekt, nicht wie eine fehlende Funktion. */}
                            {onReply && (
                                <Button onClick={() => onReply(false)} size="sm" title={labels.reply}>
                                    <Reply className="mr-2 h-4 w-4" />
                                    {labels.reply}
                                </Button>
                            )}

                            {onReply && hasMoreRecipients && (
                                <Button onClick={() => onReply(true)} size="sm" variant="outline" title={labels.replyAll}>
                                    <Users className="h-4 w-4" />
                                </Button>
                            )}

                            {onForward && (
                                <Button onClick={onForward} size="sm" variant="outline" title={labels.forward}>
                                    <Forward className="h-4 w-4" />
                                </Button>
                            )}

                            {extraActions}

                            <Button onClick={onToggleRead} size="sm" variant="outline" title={message.is_read ? labels.markUnread : labels.markRead}>
                                {message.is_read ? <Mail className="h-4 w-4" /> : <MailOpen className="h-4 w-4" />}
                            </Button>

                            <Button onClick={onToggleFlag} size="sm" variant="outline" title={message.is_flagged ? labels.unflag : labels.flag}>
                                <Star className={cn('h-4 w-4', message.is_flagged ? 'fill-yellow-400 text-yellow-400' : '')} />
                            </Button>

                            {moveControl}

                            {/* The button says how many messages it moves. */}
                            <Button
                                onClick={onArchive}
                                size="sm"
                                variant="outline"
                                disabled={busy?.archiving}
                                title={archiveCount > 1 ? labels.archiveConversation(archiveCount) : labels.archive}
                            >
                                {busy?.archiving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Archive className="h-4 w-4" />}
                                {archiveCount > 1 && <span className="ml-1 text-xs">{archiveCount}</span>}
                            </Button>

                            <Button
                                onClick={onDelete}
                                size="sm"
                                variant="outline"
                                disabled={busy?.deleting}
                                title={labels.delete}
                                className="text-destructive hover:text-destructive"
                            >
                                {busy?.deleting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
                            </Button>
                        </div>
                    )}
                </div>

                {/*
                    Eine Spalte unter `sm`, zwei ab `sm`.

                    Zwei Spalten auf 358 px sind je rund 175 px, und eine
                    Mailadresse bricht nicht von selbst. Gemessen am
                    30.10.2026 (`mail-rollout`, iPhone 14): „Von:
                    department@peppermi" und „Datum:" lagen uebereinander.
                    `break-words` dazu, damit eine lange Adresse umbricht
                    statt auszulaufen — `truncate` waere hier falsch, eine
                    halbe Adresse ist keine.
                */}
                <div className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                    <div className="min-w-0 break-words">
                        <span className="text-muted-foreground">{labels.from}</span>
                        <span className="ml-2">
                            {message.from_name || message.from_address}
                            {message.from_name && <span className="text-muted-foreground"> &lt;{message.from_address}&gt;</span>}
                        </span>
                    </div>
                    <div className="min-w-0 break-words">
                        <span className="text-muted-foreground">{labels.date}</span>
                        <span className="ml-2">{formatDateTime(message.date)}</span>
                    </div>
                    {message.to.length > 0 && (
                        <div className="col-span-2">
                            <span className="text-muted-foreground">{labels.to}</span>
                            <span className="ml-2">{formatAddressList(message.to)}</span>
                        </div>
                    )}
                    {message.cc.length > 0 && (
                        <div className="col-span-2">
                            <span className="text-muted-foreground">{labels.cc}</span>
                            <span className="ml-2">{formatAddressList(message.cc)}</span>
                        </div>
                    )}
                </div>

                {message.attachments.length > 0 && (
                    <div className="flex flex-wrap gap-2 pt-2" data-slot="mail-message-attachments">
                        {message.attachments.map((attachment) => (
                            <a
                                key={attachment.index}
                                href={attachmentUrl(attachment)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1.5 rounded-md border bg-secondary px-2 py-1 text-xs transition-colors hover:bg-secondary/70"
                                title={labels.downloadAttachment(attachment.filename)}
                            >
                                <Paperclip className="h-3 w-3 shrink-0" />
                                <span className="max-w-[220px] truncate">{attachment.filename}</span>
                                <span className="text-muted-foreground">({formatFileSize(attachment.size)})</span>
                                <Download className="h-3.5 w-3.5 shrink-0 opacity-70" />
                            </a>
                        ))}
                    </div>
                )}
            </div>

            <div className="flex-1 overflow-hidden">
                <EmailBodyViewer labels={labels.body} bodyHtml={message.body_html} bodyText={message.body_text} blockRemoteImages />
            </div>
        </div>
    );
}

export default MailMessageView;
