import { createContext, useContext } from 'react';

/**
 * Welche der drei Flaechen auf einem schmalen Bildschirm die GANZE Breite hat.
 *
 * ## Warum es diesen Zustand ueberhaupt gibt
 *
 * Der Rahmen in {@see MailboxBrowser} ist dreispaltig: Ordner (192px), Liste
 * (320px), Lesebereich (der Rest). Auf einem Telefon mit 390px sind schon die
 * ersten zwei 512px breit — der Lesebereich beginnt dann ausserhalb des
 * Bildschirms, und man liest eine Mail durch einen Spalt. Gemeldet als #1079
 * und #1071 von Chris Tolksdorf, beide zum iPhone.
 *
 * Unterhalb von `md` zeigt der Rahmen deshalb nur EINE Flaeche. Welche, steht
 * hier.
 *
 * ## Warum als Kontext und nicht als Eigenschaft
 *
 * Weil das Umschalten dort passieren muss, wo man tippt — in der Zeile einer
 * Nachricht und am Ordner —, und beide stecken als `children` im Rahmen. Als
 * Eigenschaft muesste JEDES Produkt sie verdrahten, und dann ist mobil genau
 * so lange kaputt, wie ein Produkt es nicht getan hat.
 *
 * Liste und Ordner liegen aber selbst im Paket ({@see MailMessageList},
 * {@see MailFolderList}). Sie greifen den Kontext hier ab und schalten selbst.
 * Damit wirkt der Fix in allen vier Produkten, ohne dass eines eine Zeile
 * aendert — CRM, Verwaltung und AI Brain ueber {@see MailboxFlaeche}, der
 * Projekt-Manager direkt am Rahmen.
 *
 * ## Oberhalb von `md` sagt dieser Zustand nichts
 *
 * Dort stehen alle Spalten gleichzeitig, und das Umschalten ist wirkungslos —
 * die Sichtbarkeit haengt an `md:`-Klassen, nicht an diesem Wert. Deshalb darf
 * ein Tippen auf eine Zeile ihn auch am Schreibtisch setzen: Es aendert nichts,
 * und der Rahmen braucht keine Fensterbreite zu kennen. Kein `matchMedia`, kein
 * zweiter Stand, der beim ersten Zeichnen noch nicht stimmt.
 */
export type MailboxPane = 'folders' | 'list' | 'view';

export interface MailboxPaneControl {
    /** Die Flaeche, die unterhalb von `md` sichtbar ist. */
    pane: MailboxPane;
    /** Holt eine Flaeche nach vorn. Oberhalb von `md` ohne Wirkung. */
    showPane: (pane: MailboxPane) => void;
}

/**
 * Ohne Rahmen darueber: die Liste, und Umschalten tut nichts.
 *
 * Das ist der Fall, in dem ein Produkt {@see MailMessageList} allein benutzt —
 * etwa in einer Seitenspalte. Ein `null`-Kontext wuerde dort beim Abgreifen
 * werfen; ein ruhender tut, was man erwartet: nichts.
 */
const ruhend: MailboxPaneControl = {
    pane: 'list',
    showPane: () => {},
};

export const MailboxPaneContext = createContext<MailboxPaneControl>(ruhend);

export function useMailboxPane(): MailboxPaneControl {
    return useContext(MailboxPaneContext);
}

export default MailboxPaneContext;
