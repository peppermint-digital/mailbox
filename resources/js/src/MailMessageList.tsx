import { ChevronDown, ChevronRight, Mail, Paperclip, Star } from 'lucide-react';
import { useState } from 'react';
import type { MouseEvent, ReactElement, ReactNode } from 'react';
import type { DisplayRow, MessageHandle, RowMessage } from './rows';
import { Checkbox } from './ui/checkbox';
import { Skeleton } from './ui/skeleton';
import { Tooltip, TooltipContent, TooltipTrigger } from './ui/tooltip';
import { cn } from './ui/utils';

/**
 * The message list of a mailbox — flat messages or conversations, one row each.
 *
 * ## Everything the product knows comes in as a prop
 *
 * The list renders rows; it does not fetch them, does not know what a folder
 * is called in your language, and does not know what your product hangs on a
 * message. Three injection points carry that:
 *
 * - `labels` — every word. A list is almost nothing but words.
 * - `formatDate` / `formatSender` — both are locale decisions
 * - `rowAccessory` — what your product shows on a row (an assignee's initials
 *   in one product, a linked contact in the next)
 *
 * ## The rules that are easy to lose
 *
 * A stored reply (`isOutbound`) is not a mailbox message: it has no checkbox,
 * it cannot be clicked open, and its sender is replaced by a word of yours.
 * Together with `navigableRows` that also keeps the keyboard off it — an arrow
 * key landing on a row that will not open looks like the keyboard is stuck.
 *
 * The unread badge sits on conversation heads only (`!isMember`), and the
 * "newest" mark appears only while a conversation is expanded — collapsed,
 * there is exactly one row per conversation anyway.
 *
 * In search, what matters is WHERE a hit sits: a folder badge for hits outside
 * the current folder, and an archive badge for hits that no longer sit in the
 * mailbox at all.
 */

export interface MailMessageListLabels {
    /** Title of the unread dot. */
    unread: string;
    /** Replaces the sender on an own stored reply, e.g. "Your reply". */
    outboundSender: string;
    /** Badge on an own stored reply, e.g. "sent". */
    outboundBadge: string;
    /** Title of that badge — say where the message actually sits. */
    outboundBadgeTitle: string;
    /** Title of the expand button. */
    expandThread: string;
    /** Title of the collapse button. */
    collapseThread: string;
    /** Badge on the newest message of an expanded conversation. */
    newest: string;
    /** Title of that badge. */
    newestTitle: string;
    /** The unread badge, e.g. (3) => "3 new". */
    unreadCount: (count: number) => string;
    /** Title of the unread badge; total is the conversation's size. */
    unreadCountTitle: (count: number, total: number) => string;
    /** Badge for a hit that only lives in the application's archive. */
    archived: string;
    /** Title of that badge. */
    archivedTitle: string;
    /** Title of the folder badge on a hit from another folder. */
    foundInFolder: (folder: string) => string;
    /** Accessible label of the flag star. */
    flagged: string;
    /** Accessible label of the attachment clip. */
    hasAttachments: string;
    /**
     * Accessible name of a row's checkbox, e.g. (subject) => `Select "${subject}"`.
     *
     * An icon-only checkbox with no name is announced as "checkbox" and nothing
     * else — in a list of twenty rows that is twenty identical controls.
     */
    selectMessage: (subject: string) => string;
    /** Shown when the folder has nothing in it. Omit to render nothing. */
    empty?: string;
    /** The same, but after a search that found nothing. */
    emptyInSearch?: string;
}

export interface MailMessageListProps<M extends RowMessage> {
    rows: DisplayRow<M>[];
    labels: MailMessageListLabels;
    /** The message currently open, so its row can be marked. */
    openedUid?: MessageHandle | null;
    /** Which rows are ticked. */
    selectedUids?: ReadonlySet<MessageHandle>;
    /** Which conversations are expanded, by `key`. */
    expandedThreads?: ReadonlySet<string>;
    /** Search changes what a row has to say about itself. */
    isSearchMode?: boolean;
    /** The folder the list is showing — a hit from elsewhere gets a badge. */
    currentFolder?: string;
    formatDate: (iso: string | null) => string;
    formatSender: (message: M) => string;
    onOpen: (message: M) => void;
    onToggleThread?: (key: string) => void;
    onToggleSelect?: (uid: MessageHandle, checked: boolean) => void;
    /** What the product hangs on a row — an assignee, a linked contact. */
    rowAccessory?: (row: DisplayRow<M>) => ReactNode;
    /** The product's own row menu, e.g. right-click to assign. */
    onRowContextMenu?: (event: MouseEvent<HTMLElement>, message: M) => void;
    /** While true the rows give way to `loadingPlaceholder`. */
    loading?: boolean;
    /** What to show while loading. Without it the list simply stays empty. */
    loadingPlaceholder?: ReactNode;
}

/**
 * Zeigt beim Ueberfahren den vollstaendigen Text.
 *
 * In einer 320 px schmalen Spalte ist fast jeder Absender und fast jeder
 * Betreff abgeschnitten. Das native `title`-Attribut war der erste Versuch —
 * der Browser zeigt es erst nach ein bis zwei Sekunden und als kleinen grauen
 * Kasten. Wer den Betreff lesen will, hat bis dahin schon geklickt.
 *
 * Nur wenn wirklich abgeschnitten ist: Ein Popover ueber einem Text, den man
 * ohnehin ganz sieht, ist Rauschen. Gemessen wird beim Ueberfahren an der
 * echten Breite (`scrollWidth > clientWidth`), nicht an einer Zeichenzahl —
 * die haengt von Schriftart und Spaltenbreite ab.
 */
function VollerText({ text, children }: { text: string; children: ReactElement }) {
    const [abgeschnitten, setAbgeschnitten] = useState(false);

    if (!text) {
        return children;
    }

    return (
        <Tooltip>
            <TooltipTrigger
                asChild
                onMouseEnter={(e) => {
                    const el = e.currentTarget as HTMLElement;
                    setAbgeschnitten(el.scrollWidth > el.clientWidth);
                }}
            >
                {children}
            </TooltipTrigger>
            {abgeschnitten && (
                <TooltipContent side="bottom" align="start" className="max-w-md break-words">
                    {text}
                </TooltipContent>
            )}
        </Tooltip>
    );
}

export function MailMessageList<M extends RowMessage>({
    rows,
    labels,
    openedUid = null,
    selectedUids,
    expandedThreads,
    isSearchMode = false,
    currentFolder,
    formatDate,
    formatSender,
    onOpen,
    onToggleThread,
    onToggleSelect,
    rowAccessory,
    onRowContextMenu,
    loading = false,
    loadingPlaceholder,
}: MailMessageListProps<M>) {
    const ticked = selectedUids ?? new Set<MessageHandle>();
    const expanded = expandedThreads ?? new Set<string>();

    return (
        // KEINE Layout-Klassen hier. Am 21.09.2026 standen hier kurz
        // `flex-1 min-h-0 overflow-y-auto` — und der Manager, der seine Liste
        // seit je in ein eigenes ScrollArea wickelt, konnte danach gar nicht
        // mehr scrollen: Ein Scroll-Container INNERHALB eines Scroll-Containers
        // bekommt nie eine begrenzte Hoehe.
        //
        // Ein geteiltes Bauteil weiss nicht, worin es steckt. Wo gescrollt
        // wird, entscheidet, wer die Spalte fuellt.
        <div data-slot="mail-message-list">
            {/* Laden und „nichts da" sahen bisher in jedem Produkt anders aus,
                weil jedes sie selbst baute — CRM und Verwaltung zeigten in
                beiden Faellen dieselbe leere Flaeche. Wer auf eine leere Liste
                sieht, muss erkennen koennen, ob er warten soll. */}
            {loading &&
                (loadingPlaceholder ?? (
                    /* Eine Vorgabe, damit ein frisch installiertes Produkt nicht
                       erst einen Platzhalter mitbringen muss, um so auszusehen
                       wie die anderen. Wer einen eigenen hat, reicht ihn durch. */
                    <div className="space-y-2 p-2">
                        {Array.from({ length: 10 }, (_, i) => (
                            <Skeleton key={i} className="h-16 w-full" />
                        ))}
                    </div>
                ))}

            {!loading && rows.length === 0 && (labels.empty || labels.emptyInSearch) && (
                <div className="p-8 text-center text-muted-foreground" data-slot="mail-message-list-empty">
                    <Mail className="mx-auto mb-2 h-8 w-8 opacity-50" />
                    <p>{(isSearchMode ? labels.emptyInSearch : labels.empty) ?? labels.empty ?? labels.emptyInSearch}</p>
                </div>
            )}

            {!loading && rows.map((row) => {
                const isOpen = openedUid === row.msg.uid && !row.isOutbound;
                const isExpanded = expanded.has(row.key);

                return (
                    <div
                        key={`${row.msg.message_id || row.msg.uid}${row.isMember ? '-m' : ''}`}
                        data-slot="mail-message-row"
                        data-outbound={row.isOutbound ? '' : undefined}
                        className={cn(
                            'flex items-start gap-2 border-b p-2',
                            row.isMember ? 'pl-9' : '',
                            isOpen ? 'bg-primary/10' : 'hover:bg-muted/50',
                        )}
                        onContextMenu={(event) => {
                            if (onRowContextMenu && !row.isOutbound) {
                                onRowContextMenu(event, row.msg);
                            } else {
                                event.preventDefault();
                            }
                        }}
                    >
                        {row.count > 1 && (
                            <button
                                type="button"
                                className="mt-0.5 shrink-0 text-muted-foreground hover:text-foreground"
                                title={isExpanded ? labels.collapseThread : labels.expandThread}
                                onClick={(event) => {
                                    event.stopPropagation();
                                    onToggleThread?.(row.key);
                                }}
                            >
                                {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                            </button>
                        )}

                        {/* A stored reply has no uid to act on — no checkbox, just its space. */}
                        {row.isOutbound ? (
                            <span className="mt-1 w-4 shrink-0" />
                        ) : (
                            <span onClick={(event) => event.stopPropagation()} className="mt-1 shrink-0">
                                <Checkbox
                                    aria-label={labels.selectMessage(row.msg.subject)}
                                    checked={ticked.has(row.msg.uid)}
                                    onCheckedChange={(value) => onToggleSelect?.(row.msg.uid, value === true)}
                                />
                            </span>
                        )}

                        <button
                            type="button"
                            data-slot="mail-message-open"
                            className={cn('min-w-0 flex-1 text-left', row.isOutbound ? 'cursor-default' : '')}
                            disabled={row.isOutbound}
                            onClick={() => onOpen(row.msg)}
                        >
                            <div className="mb-1 flex items-start justify-between gap-2">
                                <div className="flex min-w-0 items-center gap-2">
                                    {!row.msg.is_read ? (
                                        <span className="h-2.5 w-2.5 shrink-0 rounded-full bg-blue-500" title={labels.unread} />
                                    ) : (
                                        <span className="h-2.5 w-2.5 shrink-0" />
                                    )}

                                    {/* Absender und Betreff sollen sich voneinander abheben: Der
                                        Name traegt immer mehr Gewicht als die Zeile darunter,
                                        ungelesen noch etwas mehr. Das Gewicht bleibt damit
                                        Traeger BEIDER Aussagen — „wer" und „schon gelesen?" —,
                                        ohne dass eine die andere verdeckt.

                                        `title`: In einer 320 px schmalen Spalte ist fast jeder
                                        Name abgeschnitten. Wer wissen will, wer da schreibt,
                                        soll nicht die Mail oeffnen muessen. */}
                                    <VollerText text={row.isOutbound ? labels.outboundSender : formatSender(row.msg)}>
                                        <span className={cn('truncate text-sm', !row.msg.is_read ? 'font-semibold' : 'font-medium')}>
                                            {row.isOutbound ? labels.outboundSender : formatSender(row.msg)}
                                        </span>
                                    </VollerText>

                                    {row.isOutbound && (
                                        <span
                                            className="shrink-0 rounded-full bg-primary/10 px-1.5 text-[10px] text-primary"
                                            title={labels.outboundBadgeTitle}
                                        >
                                            {labels.outboundBadge}
                                        </span>
                                    )}

                                    {row.count > 1 && (
                                        <span className="shrink-0 rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{row.count}</span>
                                    )}

                                    {/* How much of it is new. The dot says "something is", this says how much. */}
                                    {!row.isMember && Boolean(row.unreadCount) && (
                                        <span
                                            className="shrink-0 rounded-full bg-blue-500 px-1.5 text-[10px] font-semibold text-white"
                                            title={row.count > 1 ? labels.unreadCountTitle(row.unreadCount as number, row.count) : labels.unread}
                                        >
                                            {labels.unreadCount(row.unreadCount as number)}
                                        </span>
                                    )}

                                    {/* Only while expanded: collapsed there is one row per conversation anyway. */}
                                    {row.isNewest && row.count > 1 && isExpanded && (
                                        <span className="shrink-0 rounded-full border px-1.5 text-[10px] text-muted-foreground" title={labels.newestTitle}>
                                            {labels.newest}
                                        </span>
                                    )}

                                    {rowAccessory?.(row)}
                                </div>

                                <span className="flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                                    {row.msg.is_flagged && <Star className="h-3 w-3 fill-yellow-400 text-yellow-400" aria-label={labels.flagged} />}
                                    {formatDate(row.msg.date)}
                                </span>
                            </div>

                            <div className="flex items-center gap-1">
                                {row.msg.has_attachments && <Paperclip className="h-3 w-3 shrink-0 text-muted-foreground" aria-label={labels.hasAttachments} />}

                                <VollerText text={row.msg.subject}>
                                    <span
                                        className={cn(
                                            'truncate text-sm font-normal',
                                            !row.msg.is_read ? 'text-foreground' : 'text-muted-foreground',
                                        )}
                                    >
                                        {row.msg.subject}
                                    </span>
                                </VollerText>

                                {/* In search, WHERE the hit sits is the point. */}
                                {isSearchMode && row.msg.folder && row.msg.folder !== currentFolder ? (
                                    <span
                                        className="shrink-0 rounded bg-muted px-1 text-[10px] text-muted-foreground"
                                        title={labels.foundInFolder(row.msg.folder)}
                                    >
                                        {row.msg.folder}
                                    </span>
                                ) : isSearchMode && row.msg.source === 'stored' ? (
                                    <span className="shrink-0 rounded bg-muted px-1 text-[10px] text-muted-foreground" title={labels.archivedTitle}>
                                        {labels.archived}
                                    </span>
                                ) : null}
                            </div>

                            {/* KEINE Vorschauzeile.
                                
                                JMAP liefert einen Auszug des Nachrichtenkoerpers gratis
                                mit, IMAP nicht — dort kostet er je Nachricht einen
                                Rumpf-Abruf (Bug #877). Die Liste sah damit je nach
                                Postfach verschieden aus: dasselbe Produkt, zwei
                                Darstellungen, und der Unterschied lag im Protokoll.
                                
                                Entschieden am 22.09.2026, zugunsten der Gleichheit und
                                der Geschwindigkeit: Der Auszug entfaellt ueberall. Was
                                gebraucht wurde, war der VOLLE BETREFF — und der steht
                                jetzt im `title` und erscheint beim Ueberfahren. */}
                        </button>
                    </div>
                );
            })}
        </div>
    );
}

export default MailMessageList;
