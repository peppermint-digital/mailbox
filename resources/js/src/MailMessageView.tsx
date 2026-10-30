import { Archive, ChevronDown, Download, Forward, Loader2, Mail, MailOpen, Paperclip, Pencil, Reply, Star, Trash2, Users } from 'lucide-react';
import { useState, type ReactNode } from 'react';
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
    /**
     * Klebt die Knopfleiste auf dem Telefon an den unteren Rand (#9134).
     *
     * Vorgabe `false`, damit sich fuer jeden bestehenden Aufrufer nichts
     * aendert. {@see MailboxFlaeche} setzt es, weil dort der Rahmen
     * garantiert, was diese Ansicht allein nicht weiss: Unterhalb von `md`
     * steht sie als EINZIGE Flaeche auf dem ganzen Schirm (siehe
     * {@see MailboxPane}). Nur dann ist „unten" auch der untere
     * Bildschirmrand und nicht das Ende einer schmalen Spalte neben zwei
     * anderen.
     *
     * Warum hier `max-md:` (Fenster) steht und eine Zeile hoeher `@md:`
     * (Behaelter): Es sind zwei verschiedene Fragen. „Passen Betreff und
     * Leiste nebeneinander" ist eine Frage an die SPALTE. „Ist diese Ansicht
     * gerade der ganze Bildschirm" ist eine Frage an das FENSTER — und zwar an
     * genau die Schwelle, an der der Rahmen auf eine Flaeche umschaltet.
     * Dieselbe Zahl wie der Rahmen zu nehmen ist hier der Punkt, nicht eine
     * Nachlaessigkeit.
     */
    aktionenUnten?: boolean;
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
    aktionenUnten = false,
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

    /*
     * Die Kopfdaten sind auf dem Telefon zugeklappt (#9134).
     *
     * Gemessen am 30.10.2026: „Von", „Datum", „An" und „CC" stehen dort
     * untereinander und sind zusammen rund 80 px — ueber einer Mail, von der
     * auf 844 px Bildschirm ohnehin wenig uebrig bleibt. Outlook Mobile macht
     * es genauso: EINE Zeile mit Absender und Zeit, der Rest auf Tippen.
     *
     * Der Zustand steht hier und nicht in einer Klasse, weil es ein Zustand
     * IST. Dass es ihn am Schreibtisch nicht gibt, erledigt `max-md:hidden` —
     * dort steht das Raster immer offen, egal was hier steht.
     */
    const [kopfdatenOffen, setKopfdatenOffen] = useState(false);

    const isArchived = message.source === 'stored';
    const hasMoreRecipients = message.cc.length > 0 || message.to.length > 1;

    return (
        <div
            className={cn('@container flex flex-1 flex-col overflow-hidden', aktionenUnten && 'max-md:relative')}
            data-slot="mail-message-view"
        >
            <div className="space-y-3 border-b p-4">
                {/*
                    Betreff und Knopfleiste stehen UNTEREINANDER, solange sie
                    nicht nebeneinander passen — und das entscheidet der Platz,
                    nicht eine Zahl.

                    ## Vier Anlaeufe, und erst der vierte hat die richtige Frage gestellt

                    Gemeldet: Auf dem Telefon schrumpft der Betreff auf Breite 0
                    und bricht Buchstabe fuer Buchstabe um („N / Partneranfrage:
                    / CRUDE / HARZ / …"), die Leiste laeuft aus dem Bild.

                    | Anlauf | was dazukam | was er nicht loeste |
                    |---|---|---|
                    | v0.176.1 | `flex-1` an die Spalte | `flex-1` verteilt den REST einer Zeile. Es gab keinen: Die Leiste war mit 490 px breiter als der Container mit 358 px, und `shrink-0` verbot ihr zu schrumpfen. |
                    | v0.183.0 | `sm:col-span-2` an den Kopfdaten | ein echter zweiter Fehler, aber nicht dieser |
                    | v0.184.0 | `sm:` → `@container`/`@md:` | die richtige FRAGE („wie breit ist meine Spalte"), nur zu frueh beantwortet: `@md` oeffnet bei 28rem = 448 px, die Leiste braucht 444 px (CRM, Verwaltung) bzw. 490 px (AI Brain). Zwischen 448 und ~800 px fiel die Spalte wieder auf 0. |

                    Die Lehre aus den dreien steht in Memory #5024: Nicht die
                    Vermutung war falsch, die PRUEFUNG war es. `min-w-0`,
                    `flex-1` und `shrink-0` beantworten „wie weit darf dieses
                    Kind schrumpfen bzw. wachsen". Sie beantworten NICHT:
                    „passen die Kinder ueberhaupt in die Zeile".

                    ## Was jetzt dasteht — drei Teile, und keiner ist entbehrlich

                    1. `@md:flex-wrap` an DIESER Zeile. Passt die Leiste nicht
                       neben den Betreff, rutscht sie als Ganzes in die zweite
                       Zeile, und der Betreff bekommt die volle Breite. Das ist
                       der Teil, der ohne jede Schwelle auskommt.
                    2. `@md:basis-80` (20rem = 320 px) an der Betreff-Spalte.
                       Erst eine Mindestbreite macht aus „passt nicht" einen
                       Umbruch: `flex-1` ist `flex-basis: 0%`, und `flex-grow`
                       verteilt nur VERBLEIBENDEN Platz — bei negativem Rest
                       bleibt die Spalte bei 0, ob die Leiste nun umbrechen darf
                       oder nicht. Die 320 px sind an der LESBARKEIT bemessen
                       (ein langer Betreff bleibt darin dreizeilig, bei 240 px
                       wurde er vierzeilig, gemessen), nicht an der Knopfzahl —
                       und darum traegt sie auch, wenn ein Produkt ueber
                       `zusatzAktionen` Knoepfe dazustellt.
                    3. KEIN `@md:shrink-0` und KEIN `@md:flex-nowrap` mehr an
                       der Leiste. Mit `shrink-0` behaelt sie ihre volle
                       Inhaltsbreite und draengt die Spalte heraus; mit
                       `flex-nowrap` kann sie in ihrer eigenen Zeile nicht
                       umbrechen.

                    Jeder Teil einzeln weggenommen laesst den Fehler
                    wiederkehren — nachgemessen, siehe den Browsertest.

                    ## Die schmale Fassung ist die Vorgabe

                    `@container` am Wurzelkasten und `@md:` an den Zeilen fragen
                    die Spalte selbst, nicht das Fenster: Diese Ansicht steht
                    zwischen Ordnerliste, Mailliste und Postfach-Assistent. Ein
                    MacBook Air mit 1440 px erfuellt jedes `sm:` und hatte eine
                    Lesespalte von 280 px.

                    Fehlt einem System die `@source`-Zeile fuer dieses Paket,
                    fallen alle `@md:`-Klassen weg und es bleibt bei
                    untereinander — unschoen am grossen Schirm, aber lesbar.
                    Andersherum waere es unlesbar.

                    ## Wo der Nachweis liegt

                    `resources/js/tests/browser/leseansicht-breiten.test.ts` —
                    echtes Chromium, echtes Tailwind, 320 bis 1600 px in
                    40-px-Schritten, fuer 8 und fuer 9 Knoepfe. Der jsdom-Test
                    daneben haelt nur fest, DASS die Klassen dastehen; das taten
                    sie bei jedem der vier Anlaeufe.
                */}
                <div className="flex flex-col gap-3 @md:flex-row @md:flex-wrap @md:items-start @md:justify-between @md:gap-4">
                    <div className="min-w-0 flex-1 space-y-1 @md:basis-80">
                        <h2 className="text-lg font-semibold">{message.subject}</h2>
                        {headerAccessory}
                    </div>

                    {isArchived ? (
                        <div className="flex flex-wrap items-center gap-2" data-slot="mail-message-archived">
                            <span className="rounded bg-muted px-2 py-1 text-xs text-muted-foreground" title={labels.archivedTitle}>
                                {labels.archived}
                            </span>
                        </div>
                    ) : (
                        <div
                            className={cn(
                                'flex flex-wrap items-center gap-2',
                                /*
                                    Am unteren Rand DIESER Flaeche statt neben
                                    dem Betreff. `absolute` und nicht `fixed`:
                                    Der Bezug soll die Leseansicht sein und
                                    nicht das Fenster — sonst haengt die Leiste
                                    an einer Vorfahrenkette, die ein Produkt mit
                                    einem einzigen `transform` unter sich
                                    wegziehen kann, und sie stuende ploetzlich
                                    mitten im Bild.
                                */
                                aktionenUnten &&
                                    'max-md:absolute max-md:inset-x-0 max-md:bottom-0 max-md:z-10 max-md:border-t max-md:bg-background max-md:px-3 max-md:py-2',
                            )}
                            data-slot="mail-message-actions"
                        >
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
                            {/*
                                Auf dem Telefon nur das Symbol (#9134).

                                Mit dem Wort ist „Antworten" rund 114 px breit,
                                und die neun Knoepfe brauchen zusammen 483 px.
                                Auf 358 px Inhaltsbreite bricht die Leiste
                                dadurch in ZWEI Reihen um, von denen die zweite
                                einen einzigen Knopf traegt — am Bild gesehen,
                                nicht an den Zahlen. Ohne das Wort sind es
                                neun gleiche Felder in einer Reihe.

                                Das Wort bleibt als `title` und als Name fuer
                                die Vorlesehilfe stehen: Es verschwindet aus dem
                                Bild, nicht aus der Bedienung.
                            */}
                            {onReply && (
                                <Button onClick={() => onReply(false)} size="sm" title={labels.reply} aria-label={labels.reply}>
                                    <Reply className={cn('mr-2 h-4 w-4', aktionenUnten && 'max-md:mr-0')} />
                                    <span className={cn(aktionenUnten && 'max-md:hidden')}>{labels.reply}</span>
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

                    ## Und `sm:col-span-2`, nicht `col-span-2`

                    Das ist der Teil, den eine Zusicherung auf Klassennamen
                    NICHT gefunden haette, und den erst ein Bild zeigte:
                    `grid-cols-1` stand da und das Raster war trotzdem
                    zweispaltig.

                    Gemessen am 30.10.2026 im echten Browser auf 390 px, am
                    Element mit `grid grid-cols-1 … sm:grid-cols-2`:

                        gridTemplateColumns   „166px 184px"

                    Zwei Spuren, und zwar UNGLEICHE — das ist kein
                    `grid-cols-2` (das waere zweimal 1fr). Ein Kind mit
                    `col-span-2` zwingt das Raster, eine zweite Spur ANZULEGEN,
                    auch wenn die Vorlage nur eine nennt. Die beiden kurzen
                    Felder darueber rutschen dann nebeneinander hinein.

                    Zur Kontrolle im selben Lauf ein Probe-Element eingehaengt:
                    `grid grid-cols-1` allein ergab „358px", also eine Spur.
                    Die Klasse wirkte; das Kind hat sie ueberstimmt.
                */}
                {/*
                    Die Zusammenfassung fuer das Telefon: Absender und Zeit, wie
                    in der Liste darueber. Ein Knopf und keine Ueberschrift — er
                    tut etwas, also gehoert er auf die Tastatur und braucht
                    einen Zustand, den eine Vorlesehilfe ansagen kann.
                */}
                {aktionenUnten && (
                    <button
                        type="button"
                        data-slot="mail-message-kopfdaten-aufklapper"
                        aria-expanded={kopfdatenOffen}
                        className="-mx-1 flex min-h-10 w-full items-center gap-2 rounded px-1 text-left text-sm md:hidden"
                        onClick={() => setKopfdatenOffen((war) => !war)}
                    >
                        <ChevronDown className={cn('h-4 w-4 shrink-0 text-muted-foreground transition-transform', kopfdatenOffen && 'rotate-180')} />
                        <span className="min-w-0 flex-1 truncate">{message.from_name || message.from_address}</span>
                        <span className="shrink-0 text-xs text-muted-foreground">{formatDateTime(message.date)}</span>
                    </button>
                )}

                <div
                    className={cn(
                        'grid grid-cols-1 gap-2 text-sm @md:grid-cols-2',
                        aktionenUnten && !kopfdatenOffen && 'max-md:hidden',
                    )}
                    data-slot="mail-message-kopfdaten"
                >
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
                        <div className="min-w-0 break-words @md:col-span-2">
                            <span className="text-muted-foreground">{labels.to}</span>
                            <span className="ml-2">{formatAddressList(message.to)}</span>
                        </div>
                    )}
                    {message.cc.length > 0 && (
                        <div className="min-w-0 break-words @md:col-span-2">
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

            {/*
                Luft unter dem Text, so hoch wie die Leiste werden kann. Ohne
                sie liegt die Leiste auf den letzten Zeilen der Mail — und
                „nichts liegt uebereinander" ist der Punkt dieser Aenderung,
                nicht eine Nebenbedingung.

6rem = 96 px, und die Zahl ist gemessen, nicht gegriffen: Mit
                NEUN Knoepfen (AI Brain, Verschieben plus „Aus der Ablage
                entfernen") bricht die Leiste auf 320 px in zwei Reihen und ist
                89 px hoch. Mit sieben passt sie in eine und ist 49 px hoch.

                Zuerst standen hier 80 px — gerechnet fuer EINE Reihe, weil
                „Antworten" auf dem Telefon sein Wort verliert und die Reihe
                damit schmal genug schien. Die Messreihe hat den Fall mit neun
                Knoepfen gemessen und die 9 px Unterschied gemeldet, bevor
                irgendwer sie gesehen haette. Sie prueft die Polsterung gegen
                die gemessene Leistenhoehe — wer hier kuerzt, faellt auf.
            */}
            <div className={cn('flex-1 overflow-hidden', aktionenUnten && 'max-md:pb-24')}>
                <EmailBodyViewer labels={labels.body} bodyHtml={message.body_html} bodyText={message.body_text} blockRemoteImages />
            </div>
        </div>
    );
}

export default MailMessageView;
