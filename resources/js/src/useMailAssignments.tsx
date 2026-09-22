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
    user_id: number;
    name: string;
    initials: string;
}

export interface MailAssignmentSource {
    /** Die Personen, denen zugewiesen werden darf. */
    users: (accountId: number | string, signal: AbortSignal) => Promise<MailAssignee[]>;
    /** Die offenen Zuweisungen dieses Postfachs. */
    load: (accountId: number | string, signal: AbortSignal) => Promise<MailAssignment[]>;
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
    const [busy, setBusy] = useState(false);

    const holeUsers = source.users;
    const holeZuweisungen = source.load;

    useEffect(() => {
        if (accountId === null || accountId === '') {
            setUsers([]);
            setAssignments([]);

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
                setAssignments(offene);
            } catch {
                // Ohne Zuweisungen bleibt das Postfach benutzbar. Eine
                // Fehlermeldung ueber der Liste waere hier schlimmer als die
                // fehlende Anzeige: Sie behauptet eine Stoerung des Postfachs.
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
            setAssignments(await holeZuweisungen(accountId, abbruch.signal));
        } catch {
            // siehe oben
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

    return { users, assignments, fuer, zuweisen, aufheben, busy, neuLaden };
}
