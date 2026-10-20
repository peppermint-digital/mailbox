import { useCallback, useEffect, useMemo, useState } from 'react';
import type { RowMessage } from './rows';

/**
 * Wer sich um welche Unterhaltung kuemmert.
 *
 * ## Warum ein eigener Haken und nicht ein Feld an der Nachricht
 *
 * Eine Zuweisung gehoert nicht der Nachricht, sondern der KETTE — und sie
 * aendert sich, ohne dass die Nachricht sich aendert. Haengte sie an der
 * Zeile, muesste nach jedem Zuweisen die ganze Liste neu geladen werden. So
 * wird einmal je Postfach geladen und danach nur noch der eine Eintrag
 * angefasst.
 *
 * ## Zuordnen ueber zwei Schluessel
 *
 * Gesucht wird erst nach der Kette, dann nach der Nachricht. Der zweite Weg
 * faengt den Altbestand auf: Zuweisungen, die entstanden sind, bevor es
 * Kettenkennungen gab.
 */
export interface MailAssignee {
    id: number;
    name: string;
    initials: string;
}

export interface MailAssignment {
    message_id: string;
    thread_id: string | null;
    /**
     * Die oertliche Benutzer-Nummer — oder `null`.
     *
     * `null` ist seit dem 19.10.2026 ein echter Fall, kein Fehler: Die
     * Zustaendigkeit liegt in der Mitte und kann fuer jemanden stehen, den
     * DIESES System nicht kennt. Dann traegt das Abzeichen die Adresse.
     *
     * Sie trotzdem anzuzeigen ist der Punkt. Die Zeile weglassen hiesse: Die
     * Mail sieht frei aus, obwohl jemand an ihr arbeitet.
     */
    user_id: number | null;
    /** Die Adresse — die Kennung, die ueber Systemgrenzen traegt. */
    email?: string;
    name: string;
    initials: string;
}

/**
 * Die Antwort auf „wer kuemmert sich hier um was".
 *
 * `reachable: false` heisst: Es war niemand zu fragen. Das ist NICHT dasselbe
 * wie eine leere Liste, und die Verwechslung ist die teuerste in diesem
 * Bereich — jede Mail saehe frei aus, zwei Leute fingen dieselbe Sache an,
 * und niemand bekaeme eine Meldung.
 */
export interface MailAssignmentsAnswer {
    assignments: MailAssignment[];
    reachable: boolean;
}

export interface MailAssignmentSource {
    /** Die Personen, denen zugewiesen werden darf. */
    users: (accountId: number | string, signal: AbortSignal) => Promise<MailAssignee[]>;
    /** Die offenen Zuweisungen dieses Postfachs — und ob sie zu holen waren. */
    load: (accountId: number | string, signal: AbortSignal) => Promise<MailAssignmentsAnswer>;
    assign: (accountId: number | string, message: RowMessage, userId: number) => Promise<boolean>;
    unassign: (accountId: number | string, message: RowMessage) => Promise<boolean>;
}

export interface MailAssignmentOptions {
    accountId: number | string | null;
    source: MailAssignmentSource;
    onFailure?: (meldung: string) => void;
}

export function useMailAssignments({ accountId, source, onFailure }: MailAssignmentOptions) {
    const [users, setUsers] = useState<MailAssignee[]>([]);
    const [assignments, setAssignments] = useState<MailAssignment[]>([]);
    // Bis zur ersten Antwort gilt „erreichbar": Ein Hinweis, der schon beim
    // Laden steht, waere eine Stoerungsmeldung fuer einen normalen Zustand.
    const [erreichbar, setErreichbar] = useState(true);
    const [busy, setBusy] = useState(false);

    const holeUsers = source.users;
    const holeZuweisungen = source.load;

    useEffect(() => {
        if (accountId === null || accountId === '') {
            setUsers([]);
            setAssignments([]);
            setErreichbar(true);

            return;
        }

        const abbruch = new AbortController();

        void (async () => {
            try {
                const [personen, offene] = await Promise.all([
                    holeUsers(accountId, abbruch.signal),
                    holeZuweisungen(accountId, abbruch.signal),
                ]);

                setUsers(personen);
                setAssignments(offene.assignments);
                setErreichbar(offene.reachable);
            } catch {
                /*
                 * Ohne Zuweisungen bleibt das Postfach benutzbar. Eine
                 * Fehlermeldung ueber der Liste waere schlimmer als die
                 * fehlende Anzeige: Sie behauptet eine Stoerung des Postfachs.
                 *
                 * Gemerkt wird es trotzdem. Seit die Zustaendigkeit in der
                 * Mitte liegt, ist „nicht geholt" ein echter Zustand — und
                 * eine stille leere Liste sagt „niemand zustaendig", was
                 * falsch ist und nicht bloss fehlend.
                 */
                setErreichbar(false);
            }
        })();

        return () => abbruch.abort();
    }, [accountId, holeUsers, holeZuweisungen]);

    /** Nachschlagen nach Kette, ersatzweise nach Nachricht. */
    const nachKette = useMemo(() => {
        const karte = new Map<string, MailAssignment>();

        for (const a of assignments) {
            if (a.thread_id) {
                karte.set(`t:${a.thread_id}`, a);
            }

            karte.set(`m:${a.message_id}`, a);
        }

        return karte;
    }, [assignments]);

    const fuer = useCallback(
        (message: Pick<RowMessage, 'message_id'> & { thread_id?: string | null }): MailAssignment | null => {
            if (message.thread_id) {
                const ueberKette = nachKette.get(`t:${message.thread_id}`);

                if (ueberKette) {
                    return ueberKette;
                }
            }

            return (message.message_id ? nachKette.get(`m:${message.message_id}`) : undefined) ?? null;
        },
        [nachKette],
    );

    const neuLaden = useCallback(async () => {
        if (accountId === null || accountId === '') {
            return;
        }

        const abbruch = new AbortController();

        try {
            const antwort = await holeZuweisungen(accountId, abbruch.signal);

            setAssignments(antwort.assignments);
            setErreichbar(antwort.reachable);
        } catch {
            // siehe oben
            setErreichbar(false);
        }
    }, [accountId, holeZuweisungen]);

    const zuweisen = useCallback(
        async (message: RowMessage, userId: number) => {
            if (accountId === null || accountId === '' || busy) {
                return;
            }

            setBusy(true);

            try {
                if (await source.assign(accountId, message, userId)) {
                    await neuLaden();
                } else {
                    onFailure?.('Zuweisen nicht möglich.');
                }
            } finally {
                setBusy(false);
            }
        },
        [accountId, busy, source, neuLaden, onFailure],
    );

    const aufheben = useCallback(
        async (message: RowMessage) => {
            if (accountId === null || accountId === '' || busy) {
                return;
            }

            setBusy(true);

            try {
                if (await source.unassign(accountId, message)) {
                    await neuLaden();
                } else {
                    onFailure?.('Zuweisung aufheben nicht möglich.');
                }
            } finally {
                setBusy(false);
            }
        },
        [accountId, busy, source, neuLaden, onFailure],
    );

    return { users, assignments, fuer, zuweisen, aufheben, busy, neuLaden, erreichbar };
}
