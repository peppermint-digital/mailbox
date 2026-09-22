import { useEffect, useRef } from 'react';
import { navigableRows } from './rows';
import type { DisplayRow, MessageHandle, RowMessage } from './rows';

/**
 * Oeffnet beim Betreten eines Ordners von selbst eine Nachricht.
 *
 * ## Warum
 *
 * Der Browser startete leer: links eine Liste, rechts eine graue Flaeche mit
 * „Wählen Sie eine E-Mail aus". Jeder Aufruf begann mit einem Klick, der immer
 * derselbe war.
 *
 * ## Warum die letzte GELESENE und nicht die neueste
 *
 * Weil Oeffnen als Lesen zaehlt. Wer das Postfach aufmacht und dabei
 * automatisch die neueste — womoeglich ungelesene — Nachricht geoeffnet
 * bekommt, hat sie damit stillschweigend als gelesen markiert, ohne sie
 * gesehen zu haben. Bei einem Gruppenpostfach heisst das: Die Kollegin sieht
 * sie nicht mehr als neu.
 *
 * Eine bereits gelesene Nachricht zu oeffnen kann dagegen nichts kaputt
 * machen. Sie ist gelesen, sie bleibt gelesen.
 *
 * Gibt es keine gelesene Nachricht, bleibt die Ansicht leer. Das ist der
 * richtige Preis: lieber nichts oeffnen als den Ungelesen-Stand veraendern.
 *
 * ## Einmal je Ordner
 *
 * Der Haken merkt sich, wofuer er schon gearbeitet hat (`key` — ueblicherweise
 * Postfach und Ordner). Ohne das wuerde er nach jedem Schliessen sofort wieder
 * oeffnen, und die Ansicht liesse sich gar nicht mehr leeren.
 */
export interface AutoOpenOptions<M extends RowMessage> {
    rows: DisplayRow<M>[];
    /** Postfach und Ordner — wechselt der Wert, darf wieder geoeffnet werden. */
    key: string;
    /** Was gerade offen ist. Ist etwas offen, tut der Haken nichts. */
    openedUid?: MessageHandle | null;
    onOpen: (message: M) => void;
    /** Solange false, passiert nichts — etwa waehrend die Liste noch laedt. */
    enabled?: boolean;
}

export function useAutoOpen<M extends RowMessage>({ rows, key, openedUid, onOpen, enabled = true }: AutoOpenOptions<M>): void {
    const erledigt = useRef<string | null>(null);

    useEffect(() => {
        if (!enabled || rows.length === 0 || erledigt.current === key) {
            return;
        }

        // Etwas ist offen: Dann hat jemand gewaehlt, und der Haken haelt sich
        // heraus.
        if (openedUid != null) {
            erledigt.current = key;

            return;
        }

        // `navigableRows` ist dieselbe Regel wie bei der Tastatur: Was sich
        // nicht oeffnen laesst (gespeicherte Antworten), kommt nicht infrage.
        const kandidat = navigableRows(rows).find((r) => r.msg.is_read);

        // Kein gelesener Eintrag: nichts tun, aber als erledigt merken —
        // sonst laeuft die Suche bei jedem Zeichnen erneut.
        erledigt.current = key;

        if (kandidat) {
            onOpen(kandidat.msg);
        }
    }, [rows, key, openedUid, onOpen, enabled]);
}
