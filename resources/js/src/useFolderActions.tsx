import { useState } from 'react';
import type { MailFolder } from './MailFolderList';

/**
 * Ordner anlegen, umbenennen, loeschen — Zustand und Wirkung an einer Stelle.
 *
 * ## Warum das ins Paket gehoert
 *
 * Bis zum 22.09.2026 konnte nur der Projekt-Manager Ordner verwalten. CRM und
 * Verwaltung zeigten dieselbe Ordnerspalte, aber ohne „Neuer Ordner" und ohne
 * Kontextmenue — und der Vertrag kannte `createFolder`, `renameFolder` und
 * `deleteFolder` die ganze Zeit. Wieder fehlte nur die Strecke dorthin.
 *
 * ## Warum EIN Haken und nicht drei
 *
 * Wegen `busy`. Solange ein Ordnerbefehl laeuft, duerfen die anderen nicht
 * ausloesen — drei getrennte Haken haetten drei getrennte „gerade
 * beschaeftigt", und die haetten voneinander nichts gewusst.
 *
 * ## Was der Haken NICHT besitzt
 *
 * Die Ordnerliste und den gerade geoeffneten Ordner. Beides gehoert der Seite:
 * Die Liste haengt an der Postfach-Auswahl, der geoeffnete Ordner steuert die
 * Nachrichtenliste. Der Haken bekommt `onChanged` — was nach einer Aenderung
 * zu laden ist, weiss die Seite besser.
 *
 * ## Meldungen gehen hinaus, nicht hinein
 *
 * Das Paket kennt kein Benachrichtigungssystem. `onMessage` bekommt, was
 * passiert ist; ob daraus ein Toast, eine Zeile oder nichts wird, entscheidet
 * das Produkt.
 */
export interface FolderDialogState {
    open: boolean;
    mode: 'create' | 'rename';
    name: string;
    path: string;
}

export interface FolderMenuState<F> {
    open: boolean;
    x: number;
    y: number;
    folder: F | null;
}

export interface FolderActionLabels {
    created: string;
    renamed: string;
    deleted: string;
    /** Wenn der Server nichts Eigenes sagt. */
    failed: string;
}

export interface FolderActionOptions<F> {
    /** Das Postfach, in dem gearbeitet wird — ohne das geht nichts. */
    accountId: number | string | null;
    /** Die Adresse der Ordner-Endpunkte. POST/PATCH/DELETE gehen alle dorthin. */
    endpoint: (accountId: number | string) => string;
    csrfToken: string;
    labels: FolderActionLabels;
    /** Nach einer erfolgreichen Aenderung: Liste neu laden. */
    onChanged: () => Promise<void> | void;
    /**
     * Wird gerufen, wenn der geloeschte Ordner der gerade geoeffnete war.
     * Ohne das bliebe die Nachrichtenliste auf einem Ordner stehen, den es
     * nicht mehr gibt — und jeder Klick darin liefe ins Leere.
     */
    onDeletedCurrent?: () => Promise<void> | void;
    /** Der gerade geoeffnete Ordner, damit der Fall oben erkannt wird. */
    selectedFolder?: string | null;
    onMessage?: (art: 'ok' | 'fehler', text: string) => void;
    fetch?: typeof globalThis.fetch;
}

export function useFolderActions<F extends MailFolder = MailFolder>({
    accountId,
    endpoint,
    csrfToken,
    labels,
    onChanged,
    onDeletedCurrent,
    selectedFolder,
    onMessage,
    fetch: eigenesFetch,
}: FolderActionOptions<F>) {
    const [dialog, setDialog] = useState<FolderDialogState>({ open: false, mode: 'create', name: '', path: '' });
    const [menu, setMenu] = useState<FolderMenuState<F>>({ open: false, x: 0, y: 0, folder: null });
    const [deleteTarget, setDeleteTarget] = useState<F | null>(null);
    const [busy, setBusy] = useState(false);

    async function anfrage(methode: string, koerper: Record<string, unknown>): Promise<boolean> {
        if (accountId === null || accountId === '') {
            return false;
        }

        const hole = eigenesFetch ?? globalThis.fetch;

        let antwort: Response;

        try {
            antwort = await hole(endpoint(accountId), {
                method: methode,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify(koerper),
            });
        } catch {
            onMessage?.('fehler', labels.failed);

            return false;
        }

        if (!antwort.ok) {
            const daten = await antwort.json().catch(() => ({}));

            // Die Meldung des Servers hat Vorrang: Er weiss, ob der Ordner
            // existiert, geschuetzt ist oder der Anbieter den Namen ablehnt.
            onMessage?.('fehler', typeof daten.message === 'string' ? daten.message : labels.failed);

            return false;
        }

        return true;
    }

    async function submit(): Promise<void> {
        const name = dialog.name.trim();

        if (!name || busy) {
            return;
        }

        setBusy(true);

        try {
            const ok =
                dialog.mode === 'create'
                    ? await anfrage('POST', { path: name })
                    : await anfrage('PATCH', { path: dialog.path, name });

            if (ok) {
                onMessage?.('ok', dialog.mode === 'create' ? labels.created : labels.renamed);
                setDialog((vorher) => ({ ...vorher, open: false }));
                await onChanged();
            }
        } finally {
            setBusy(false);
        }
    }

    async function confirmDelete(): Promise<void> {
        const ordner = deleteTarget;

        if (!ordner || busy) {
            return;
        }

        setBusy(true);

        try {
            const ok = await anfrage('DELETE', { path: ordner.path });

            if (ok) {
                onMessage?.('ok', labels.deleted);
                setDeleteTarget(null);

                if (selectedFolder === ordner.path) {
                    await onDeletedCurrent?.();
                }

                await onChanged();
            }
        } finally {
            setBusy(false);
        }
    }

    return { dialog, setDialog, menu, setMenu, deleteTarget, setDeleteTarget, busy, submit, confirmDelete };
}
