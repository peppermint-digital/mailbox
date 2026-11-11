import { useEffect, useRef } from 'react';
import { navigableRows } from './rows';
import type { DisplayRow, RowMessage } from './rows';

/**
 * Oeffnet die Nachricht, auf die ein Verweis von aussen zeigt.
 *
 * ## Wozu
 *
 * Ein Produkt haelt irgendwo fest, aus welcher Mail etwas entstanden ist —
 * ein Beleg, ein Ticket, eine Aufgabe. Der Verweis dorthin konnte bisher nur
 * das POSTFACH oeffnen ({@link MailboxSeiteOptionen.anfangsKonto}); welche
 * Nachricht gemeint war, musste der Mensch selbst suchen. In der Verwaltung
 * steht an jedem Beleg die Herkunftsmail mit Konto, Ordner und UID — und der
 * Verweis darauf endete in einem Postfach mit tausend Nachrichten.
 *
 * ## Warum ein eigener Haken neben {@link useAutoOpen}
 *
 * Beide oeffnen eine Nachricht beim Betreten eines Ordners, aber sie
 * beantworten verschiedene Fragen. `useAutoOpen` sucht sich selbst etwas aus
 * („die neueste GELESENE, damit nichts still als gelesen gilt"); dieser hier
 * oeffnet, was jemand ausdruecklich verlangt hat. Zusammengelegt muesste eine
 * Funktion beide Regeln tragen, und die Begruendung fuer die eine waere am
 * anderen Fall falsch.
 *
 * Wichtig ist deshalb, dass sie sich nicht in die Quere kommen: Solange eine
 * verlangte Nachricht noch aussteht, haelt sich die Vorauswahl heraus.
 *
 * ## Warum „gelesen" hier keine Rolle spielt
 *
 * `useAutoOpen` oeffnet bewusst nur Gelesenes, weil Oeffnen als Lesen zaehlt
 * und es seine eigene Wahl ist. Hier hat ein Mensch geklickt. Wer einem
 * Verweis auf genau diese Mail folgt, will sie sehen — auch wenn sie damit
 * gelesen ist.
 *
 * ## Was passiert, wenn es die Nachricht nicht mehr gibt
 *
 * Eine IMAP-UID gilt nur innerhalb eines Ordners und nur, solange die Mail
 * dort liegt. Nach einem Umzug ins Archiv zeigt sie auf die Mail, die
 * nachgerutscht ist — der gefaehrlichere Fehler als „nicht gefunden", weil er
 * wie ein Treffer aussieht.
 *
 * Deshalb wird ausschliesslich in den geladenen Zeilen gesucht, und wenn die
 * UID dort nicht steht, passiert NICHTS — ausser dass {@link onFehlt} gerufen
 * wird. Das Produkt kann es dann sagen („diese Mail liegt nicht mehr hier"),
 * statt dass jemand eine fremde Nachricht fuer die gesuchte haelt.
 */
export interface AnfangsNachrichtOptions<M extends RowMessage> {
    rows: DisplayRow<M>[];
    /**
     * Postfach und Ordner — wechselt der Wert, wird wieder gesucht.
     *
     * Derselbe Schluessel wie bei {@link useAutoOpen}: Ohne ihn wuerde nach
     * jedem Schliessen sofort wieder geoeffnet, und die Ansicht liesse sich
     * nicht mehr leeren.
     */
    key: string;
    /** Die verlangte UID — `null` heisst: niemand hat etwas verlangt. */
    uid?: number | string | null;
    onOpen: (message: M) => void;
    /** Die verlangte Nachricht liegt nicht in diesem Ordner. */
    onFehlt?: () => void;
    /** Solange false, passiert nichts — etwa waehrend die Liste noch laedt. */
    enabled?: boolean;
}

export function useAnfangsNachricht<M extends RowMessage>({
    rows,
    key,
    uid = null,
    onOpen,
    onFehlt,
    enabled = true,
}: AnfangsNachrichtOptions<M>): void {
    const erledigt = useRef<string | null>(null);

    useEffect(() => {
        if (!enabled || uid == null || uid === '' || erledigt.current === key) {
            return;
        }

        /*
         * Eine leere Liste ist noch keine Antwort: Sie kann auch heissen,
         * dass die Zeilen noch nicht da sind. Erst wenn etwas da ist, laesst
         * sich sagen, ob die gesuchte Nachricht dabei ist.
         */
        if (rows.length === 0) {
            return;
        }

        // Als Zeichenkette verglichen: Aus einer Adresse kommt die UID als
        // solche, aus der Liste als Zahl, und '7' === 7 ist falsch. Dieselbe
        // Falle wie bei `anfangsKonto`.
        const treffer = navigableRows(rows).find((r) => String(r.msg.uid) === String(uid));

        erledigt.current = key;

        if (treffer) {
            onOpen(treffer.msg);

            return;
        }

        onFehlt?.();
    }, [rows, key, uid, onOpen, onFehlt, enabled]);
}
