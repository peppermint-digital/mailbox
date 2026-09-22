import type { ReactNode } from 'react';

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
 */

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
    /** Width of the message list column. Tailwind class, e.g. `w-80`. */
    listWidth?: string;
}

export function MailboxBrowser({ folders, list, view, aside, toolbar, showFolders = true, listWidth = 'w-80' }: MailboxBrowserProps) {
    return (
        <div className="flex flex-1 flex-col overflow-hidden" data-slot="mailbox-browser">
            {toolbar}

            <div className="flex flex-1 overflow-hidden">
                {showFolders && (
                    <div className="w-48 overflow-y-auto border-r bg-muted/30" data-slot="mailbox-folders">
                        <div className="p-2">{folders}</div>
                    </div>
                )}

                <div className={`flex ${listWidth} flex-col overflow-hidden border-r`} data-slot="mailbox-list">
                    {list}
                </div>

                <div className="flex flex-1 flex-col overflow-hidden" data-slot="mailbox-view">
                    {view}
                </div>

                {aside}
            </div>
        </div>
    );
}

export default MailboxBrowser;
