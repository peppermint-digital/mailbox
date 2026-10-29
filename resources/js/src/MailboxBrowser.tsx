import { useMemo, useState, type ReactNode } from 'react';
import { ArrowLeft, FolderOpen } from 'lucide-react';
import { MailboxPaneContext, type MailboxPane, type MailboxPaneControl } from './MailboxPane';

/**
 * The frame a mail browser sits in: folders | list | reading pane, plus
 * whatever column the product puts beside them.
 *
 * ## Why the arrangement is in the package
 *
 * It is the part every product would write identically — three columns, the
 * middle one fixed, the right one taking the rest, all of them scrolling
 * inside instead of growing the page. Written twice it drifts twice; written
 * here it is one answer.
 *
 * What differs between products is not the arrangement but what hangs in it:
 * one links a mail to a task, the next to a contact. Those go into the panes,
 * not into this frame.
 *
 * ## Wer scrollt, und wer nicht
 *
 * Der Rahmen ist so hoch wie das Fenster und schneidet ab, was darueber
 * hinausgeht. Die Ordner-Spalte scrollt als Ganzes — sie enthaelt nur Ordner.
 *
 * Die Listen-Spalte NICHT. Sie enthaelt bei einem gewachsenen Produkt mehr als
 * die Liste: Werkzeugleiste, Filter, Sammelaktionen, Blaetterung. Wuerde die
 * Spalte scrollen, wanderten die alle mit nach oben weg — und man verlaert
 * beim Blaettern genau die Knoepfe, die man zum Blaettern braucht.
 *
 * Deshalb schneidet die Spalte nur ab (`overflow-hidden`) — und WER darin
 * scrollt, entscheidet das Produkt. Es weiss als Einziges, was ausser der
 * Liste noch in der Spalte steht.
 *
 * Der Manager wickelt seine Liste seit je in ein eigenes ScrollArea. Als das
 * Scrollen am 21.09.2026 kurz in {@see MailMessageList} lag, hatte er zwei
 * Scroll-Container ineinander — und der innere bekam nie eine begrenzte Hoehe.
 * Danach ging gar nichts mehr.
 *
 * Die Spalte muss trotzdem abschneiden: Ohne das hat auch der Container des
 * Produkts keine Hoehe, an der er sich begrenzen koennte.
 *
 * ## The folder column collapses, and that is a preference
 *
 * Whether it is open is remembered by the product — it is a per-person setting
 * that outlives the page, and the package has nowhere to keep it.
 *
 * ## Drei Spalten ab `md`, EINE darunter
 *
 * Drei Spalten nebeneinander sind eine Aussage ueber die Breite: Ordner 192px
 * plus Liste 320px sind 512px, bevor der Lesebereich anfaengt. Auf einem
 * iPhone mit 390px ist er damit vollstaendig ausserhalb des Bildschirms — man
 * liest eine Mail durch einen Spalt, und die Knoepfe am rechten Rand des
 * Lesebereichs sind gar nicht erreichbar. Genau das hat Chris Tolksdorf
 * gemeldet (#1079 kritisch, #1071).
 *
 * Unterhalb von `md` steht deshalb nur eine Flaeche, und zwar ueber die ganze
 * Breite. Welche, sagt {@see MailboxPane} — Vorgabe ist die Liste, weil sie
 * die Uebersicht ist, mit der man anfaengt. Das ist wortwoertlich, was der
 * Melder wollte: „zunaechst ausschliesslich die E-Mail-Uebersicht anzeigen"
 * und die Vorschau nicht angeschnitten daneben.
 *
 * ### Warum das Umschalten NICHT an einer Eigenschaft haengt
 *
 * Weil dann jedes Produkt es verdrahten muesste und mobil so lange kaputt
 * bliebe, wie eines es nicht getan hat. Stattdessen schalten die Liste und die
 * Ordner selbst — sie liegen im Paket und greifen den Kontext ab. Ein Tippen
 * auf eine Zeile holt den Lesebereich, ein Tippen auf einen Ordner die Liste.
 * Kein Produkt aendert eine Zeile.
 *
 * ### Warum per CSS und nicht per `matchMedia`
 *
 * Die Breite entscheidet die Klasse (`md:`), nicht JavaScript. Ein gemessener
 * Zustand waere beim ersten Zeichnen noch falsch — und beim Drehen des Geraets
 * ein zweiter Ort, der nachziehen muss. So gibt es nur einen.
 *
 * Folge davon, und sie ist gewollt: Oberhalb von `md` ist der Zustand ohne
 * Wirkung. Alle Spalten stehen dort gleichzeitig, wie vorher.
 */

export interface MailboxBrowserLabels {
    /** Der Knopf, der auf dem Telefon die Ordner holt. */
    folders: string;
    /** Der Knopf, der von den Ordnern oder der Nachricht zurueck zur Liste fuehrt. */
    backToList: string;
}

/**
 * Die deutschen Woerter des Rahmens — als Vorgabe.
 *
 * Anders als die uebrigen Flaechen nimmt der Rahmen seine Texte NICHT als
 * Pflichtfeld: Er soll in allen vier Produkten wirken, ohne dass eines eine
 * Zeile aendert. Ein Pflichtfeld waere genau die Zeile. Wer andere Woerter
 * will, uebergibt `labels` — und `germanLabels.rahmen` ist dasselbe Objekt.
 */
export const mailboxBrowserLabels: MailboxBrowserLabels = {
    folders: 'Ordner',
    backToList: 'Zurück zur Liste',
};

export interface MailboxBrowserProps {
    /** The folder column. Hidden entirely when `showFolders` is false. */
    folders: ReactNode;
    /** The message list — fixed width, it is an index rather than content. */
    list: ReactNode;
    /** The reading pane, taking whatever room is left. */
    view: ReactNode;
    /** Anything the product puts beside the reading pane (an assistant, a detail rail). */
    aside?: ReactNode;
    /** The bar above the three columns: account picker, actions. */
    toolbar?: ReactNode;
    showFolders?: boolean;
    /**
     * Width of the message list column. Tailwind class, e.g. `w-80`.
     *
     * Gilt erst ab `md`: Darunter nimmt die Liste die ganze Breite, weil sie
     * dort die einzige sichtbare Flaeche ist. 320px auf einem 390px-Schirm
     * waeren 70px verschenkt.
     */
    listWidth?: string;
    /** Die Woerter der Umschaltung. Nur unterhalb von `md` sichtbar. */
    labels?: MailboxBrowserLabels;
}

export function MailboxBrowser({
    folders,
    list,
    view,
    aside,
    toolbar,
    showFolders = true,
    listWidth = 'w-80',
    labels = mailboxBrowserLabels,
}: MailboxBrowserProps) {
    const [pane, setPane] = useState<MailboxPane>('list');

    const steuerung = useMemo<MailboxPaneControl>(() => ({ pane, showPane: setPane }), [pane]);

    /*
     * Die Umschaltleiste steht UEBER den Flaechen, nicht darin — aus demselben
     * Grund, aus dem die Listen-Spalte nicht als Ganzes scrollt: Ein Knopf, der
     * mit dem Inhalt nach oben wandert, ist genau der Knopf, den man verliert.
     *
     * Und sie steht nur da, wenn sie etwas zu sagen hat. Auf der Liste ohne
     * Ordner-Spalte waere sie ein leerer Streifen mit einer Trennlinie.
     */
    const umschaltung =
        pane === 'list' ? (
            showFolders ? (
                <button
                    type="button"
                    data-slot="mailbox-pane-folders"
                    className="inline-flex min-h-10 items-center gap-2 rounded-md px-3 text-sm font-medium transition-colors hover:bg-muted"
                    onClick={() => setPane('folders')}
                >
                    <FolderOpen className="h-4 w-4 shrink-0" />
                    {labels.folders}
                </button>
            ) : null
        ) : (
            <button
                type="button"
                data-slot="mailbox-pane-back"
                className="inline-flex min-h-10 items-center gap-2 rounded-md px-3 text-sm font-medium transition-colors hover:bg-muted"
                onClick={() => setPane('list')}
            >
                <ArrowLeft className="h-4 w-4 shrink-0" />
                {labels.backToList}
            </button>
        );

    return (
        <MailboxPaneContext.Provider value={steuerung}>
            <div className="flex flex-1 flex-col overflow-hidden" data-slot="mailbox-browser">
                {toolbar}

                {umschaltung && (
                    <div className="flex items-center gap-2 border-b px-2 py-1 md:hidden" data-slot="mailbox-pane-nav">
                        {umschaltung}
                    </div>
                )}

                {/* `relative` traegt die Seitenspalte, die unterhalb von `md`
                    darueberliegt statt daneben zu stehen. */}
                <div className="relative flex flex-1 overflow-hidden">
                    {showFolders && (
                        <div
                            className={`${pane === 'folders' ? 'block' : 'hidden'} overflow-y-auto border-r bg-muted/30 max-md:w-full md:block md:w-48`}
                            data-slot="mailbox-folders"
                        >
                            <div className="p-2">{folders}</div>
                        </div>
                    )}

                    <div
                        className={`${pane === 'list' ? 'flex' : 'hidden'} flex-col overflow-hidden border-r max-md:w-full md:flex ${listWidth}`}
                        data-slot="mailbox-list"
                    >
                        {list}
                    </div>

                    <div
                        className={`${pane === 'view' ? 'flex' : 'hidden'} flex-1 flex-col overflow-hidden max-md:w-full md:flex`}
                        data-slot="mailbox-view"
                    >
                        {view}
                    </div>

                    {/* Oberhalb von `md` ist diese Huelle nicht da (`contents`) —
                        die Seitenspalte des Produkts ist dort wie vorher selbst
                        das Flex-Kind. Darunter deckt sie die Flaechen ab, statt
                        sich als vierte Spalte dazwischenzuschieben: Sie wurde
                        gerade absichtlich geoeffnet und bringt ihren eigenen
                        Schliessen-Knopf mit. */}
                    {aside && (
                        <div
                            className="max-md:absolute max-md:inset-0 max-md:z-20 max-md:flex max-md:overflow-hidden max-md:bg-background md:contents"
                            data-slot="mailbox-aside"
                        >
                            {aside}
                        </div>
                    )}
                </div>
            </div>
        </MailboxPaneContext.Provider>
    );
}

export default MailboxBrowser;
