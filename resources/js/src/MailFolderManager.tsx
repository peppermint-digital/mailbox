import { Loader2, Pencil, Trash2 } from 'lucide-react';
import { isProtectedFolder } from './folders';
import type { MailFolder } from './MailFolderList';
import type { FolderDialogState, FolderMenuState } from './useFolderActions';
import { Button } from './ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from './ui/dialog';
import { Input } from './ui/input';

/**
 * Die Bedienung der Ordnerverwaltung: Kontextmenue, Anlegen/Umbenennen-Maske
 * und die Loeschabfrage.
 *
 * ## Warum Menue und Masken zusammen in einer Datei
 *
 * Weil sie nur zusammen einen Sinn ergeben. Das Menue oeffnet die Masken, die
 * Masken schliessen sich in das Menue zurueck. Getrennt haette man zwei
 * Bauteile, die beide denselben Zustand brauchen — und die naechste Aenderung
 * fasst beide an.
 *
 * ## Geschuetzte Ordner
 *
 * Das Menue zeigt fuer den Posteingang und die Standardordner keine Eintraege,
 * sondern den Grund. Das ist Hoeflichkeit, keine Sicherheit: Die eigentliche
 * Sperre sitzt seit v0.85 serverseitig
 * (`Http\HandlesMailboxActions::schuetzeOrdner`). Vorher gab es sie NUR hier —
 * ein Aufruf des Endpunkts von Hand haette den Posteingang geloescht.
 */
export interface MailFolderManagerLabels {
    create: string;
    rename: string;
    delete: string;
    cancel: string;
    /** Ueberschrift der Maske beim Anlegen. */
    createTitle: string;
    /** Ueberschrift der Maske beim Umbenennen. */
    renameTitle: string;
    /** Hinweis, wie ein Unterordner entsteht. */
    createHint: string;
    namePlaceholder: string;
    deleteTitle: string;
    /** Die Warnung vor dem Loeschen. Der Ordnername wird eingesetzt. */
    deleteWarning: (name: string) => string;
    /** Was im Menue steht, wenn der Ordner nicht angefasst werden darf. */
    protectedFolder: string;
}

export interface MailFolderManagerProps<F extends MailFolder> {
    labels: MailFolderManagerLabels;

    menu: FolderMenuState<F>;
    onMenuChange: (zustand: FolderMenuState<F>) => void;

    dialog: FolderDialogState;
    onDialogChange: (aendern: (vorher: FolderDialogState) => FolderDialogState) => void;
    onSubmit: () => void;

    deleteTarget: F | null;
    onDeleteTargetChange: (ziel: F | null) => void;
    onConfirmDelete: () => void;

    busy: boolean;
}

const ZU = { open: false, x: 0, y: 0, folder: null } as const;

export function MailFolderManager<F extends MailFolder>({
    labels,
    menu,
    onMenuChange,
    dialog,
    onDialogChange,
    onSubmit,
    deleteTarget,
    onDeleteTargetChange,
    onConfirmDelete,
    busy,
}: MailFolderManagerProps<F>) {
    const schliesseMenue = () => onMenuChange({ ...ZU } as FolderMenuState<F>);

    return (
        <>
            {menu.open && (
                // Die Auffangflaeche liegt ueber allem: Ein Klick irgendwohin
                // schliesst das Menue. Ohne sie bleibt es stehen, bis man
                // zufaellig wieder hineinklickt.
                <div
                    className="fixed inset-0 z-50"
                    data-slot="mail-folder-menu"
                    onClick={schliesseMenue}
                    onContextMenu={(e) => {
                        e.preventDefault();
                        schliesseMenue();
                    }}
                >
                    <div
                        className="absolute min-w-44 rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
                        style={{ top: `${menu.y}px`, left: `${menu.x}px` }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        {menu.folder && !isProtectedFolder(menu.folder) ? (
                            <>
                                <button
                                    type="button"
                                    className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                                    onClick={() => {
                                        const f = menu.folder;
                                        schliesseMenue();

                                        if (f) {
                                            onDialogChange(() => ({ open: true, mode: 'rename', name: f.name, path: f.path }));
                                        }
                                    }}
                                >
                                    <Pencil className="h-4 w-4" /> {labels.rename}
                                </button>
                                <button
                                    type="button"
                                    className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm text-destructive hover:bg-destructive/10"
                                    onClick={() => {
                                        const f = menu.folder;
                                        schliesseMenue();
                                        onDeleteTargetChange(f);
                                    }}
                                >
                                    <Trash2 className="h-4 w-4" /> {labels.delete}
                                </button>
                            </>
                        ) : (
                            <div className="px-2 py-1.5 text-xs text-muted-foreground">{labels.protectedFolder}</div>
                        )}
                    </div>
                </div>
            )}

            <Dialog open={dialog.open} onOpenChange={(v) => onDialogChange((vorher) => ({ ...vorher, open: v }))}>
                <DialogContent className="sm:max-w-[420px]" data-slot="mail-folder-dialog">
                    <DialogHeader>
                        <DialogTitle>{dialog.mode === 'create' ? labels.createTitle : labels.renameTitle}</DialogTitle>
                        {dialog.mode === 'create' && <DialogDescription>{labels.createHint}</DialogDescription>}
                    </DialogHeader>
                    <Input
                        value={dialog.name}
                        placeholder={labels.namePlaceholder}
                        aria-label={labels.namePlaceholder}
                        onChange={(e) => onDialogChange((vorher) => ({ ...vorher, name: e.target.value }))}
                        onKeyUp={(e) => {
                            if (e.key === 'Enter') {
                                onSubmit();
                            }
                        }}
                    />
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => onDialogChange((vorher) => ({ ...vorher, open: false }))}>
                            {labels.cancel}
                        </Button>
                        <Button disabled={busy || !dialog.name.trim()} onClick={onSubmit}>
                            {busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                            {dialog.mode === 'create' ? labels.create : labels.rename}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={Boolean(deleteTarget)}
                onOpenChange={(v) => {
                    if (!v) {
                        onDeleteTargetChange(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-[440px]" data-slot="mail-folder-delete-dialog">
                    <DialogHeader>
                        <DialogTitle>{labels.deleteTitle}</DialogTitle>
                        <DialogDescription>{labels.deleteWarning(deleteTarget?.name ?? '')}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => onDeleteTargetChange(null)}>
                            {labels.cancel}
                        </Button>
                        <Button variant="destructive" disabled={busy} onClick={onConfirmDelete}>
                            {busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                            {labels.delete}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
