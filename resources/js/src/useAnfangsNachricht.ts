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
 * ## Zwei Wege, und warum es zwei sein muessen
 *
 * 1. **In den geladenen Zeilen.** Der schnelle Weg, ohne eine einzige
 *    Anfrage — er trifft, was gerade auf dem Schirm ist.
 * 2. **Gezielt nachladen.** Steht die Kennung dort nicht, heisst das NICHT,
 *    dass es die Mail nicht gibt: Die Liste zeigt eine Seite, das Postfach
 *    hat viele.
 *
 * Der zweite Weg hat gefehlt, und das ist am 12.11.2026 in Produktion
 * aufgefallen: Ein Verweis auf eine Mail von vorgestern fuehrte in den
 * richtigen Ordner und oeffnete NICHTS — lautlos. Gemessen an zwei Faellen
 * im selben Postfach: Kennung auf Seite 1 ging auf, Kennung auf einer
 * spaeteren Seite nicht. Das trifft genau die Mails, die man an einem Beleg
 * nachschlaegt, naemlich die aelteren.
 *
 * ## Was passiert, wenn es die Nachricht wirklich nicht gibt
 *
 * Eine IMAP-UID gilt nur INNERHALB eines Ordners. Ist die Mail dort nicht
 * mehr, liefert der Server nichts — eine UID wird nicht neu vergeben,
 * solange die `uidvalidity` des Ordners gleich bleibt (dieselbe Zusicherung,
 * auf der die Archiv-Erfassung dieses Pakets aufbaut).
 *
 * Hier stand zuerst, die UID zeige nach einem Umzug „auf die Mail, die
 * nachgerutscht ist". Das ist die Falle der SEQUENZNUMMERN und nicht die der
 * UIDs; korrigiert am 12.11.2026, nachdem eine andere Sitzung den Satz
 * uebernehmen wollte. Zwei Lagen bleiben trotzdem gefaehrlich, und gegen
 * beide ist hier gesichert:
 *
 * - **Falscher Ordner.** Dieselbe Zahl bedeutet in einem anderen Ordner eine
 *   andere Mail. Deshalb wird ausschliesslich im Ordner des Verweises
 *   nachgefragt, nie im gerade offenen.
 * - **Geaenderte `uidvalidity`.** Dann sind ALLE Kennungen dieses Ordners
 *   bedeutungslos, nicht nur eine. Davor schuetzt kein Abgleich im Browser;
 *   es ist der Grund, warum die Message-ID der haltbarere Anker waere.
 *
 * Geoeffnet wird deshalb nur, was der Server auf genau diese Kennung in
 * genau diesem Ordner herausgibt. Schlaegt das fehl, passiert nichts ausser
 * {@link onFehlt} — niemand soll eine fremde Nachricht fuer die gesuchte
 * halten.
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
    /**
     * Die Kennung steht nicht in den geladenen Zeilen — gezielt nachfragen.
     *
     * Gibt `true` zurueck, wenn die Nachricht geoeffnet wurde. Ohne diesen
     * Weg bliebe jede Mail unerreichbar, die nicht auf der ersten Seite
     * liegt; mit ihm kostet er genau eine Anfrage, und nur dann, wenn die
     * Zeilen nicht schon reichen.
     */
    nachladen?: (uid: number | string) => Promise<boolean>;
    /** Die verlangte Nachricht gibt es nicht (mehr) — weder in den Zeilen noch am Server. */
    onFehlt?: () => void;
    /** Solange false, passiert nichts — etwa waehrend die Liste noch laedt. */
    enabled?: boolean;
}

export function useAnfangsNachricht<M extends RowMessage>({
    rows,
    key,
    uid = null,
    onOpen,
    nachladen,
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

        // VOR dem Nachladen gesetzt, nicht danach: Der Haken laeuft bei jedem
        // Zeichnen erneut, und ein `await` in der Mitte wuerde sonst eine
        // zweite Anfrage anstossen, bevor die erste zurueck ist.
        erledigt.current = key;

        if (treffer) {
            onOpen(treffer.msg);

            return;
        }

        if (!nachladen) {
            onFehlt?.();

            return;
        }

        void (async () => {
            const geoeffnet = await nachladen(uid).catch(() => false);

            if (!geoeffnet) {
                onFehlt?.();
            }
        })();
    }, [rows, key, uid, onOpen, nachladen, onFehlt, enabled]);
}
