import { Fragment } from 'react';
import { germanLabels } from './germanLabels';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from './ui/dialog';
import { MAILBOX_KUERZEL } from './useMailboxKeyboard';

/**
 * Die Übersicht der Tastatur-Kürzel (A4, #9116).
 *
 * Sie lag im Manager, während die Tasten im Paket gebunden wurden — zwei Orte
 * für eine Sache. Aufgefallen ist das daran, dass `Entf` gebunden war und in
 * der Hilfe fehlte: Niemand hätte es je bemerkt, denn eine Hilfe, die etwas
 * verschweigt, sieht genauso aus wie eine vollständige.
 *
 * Jetzt steht die Liste neben den Bindungen, und ein Test geht jede Taste
 * durch, die hier versprochen wird.
 */

export interface MailboxKuerzelHilfeProps {
    offen: boolean;
    onOffenChange: (offen: boolean) => void;
}

export function MailboxKuerzelHilfe({ offen, onOffenChange }: MailboxKuerzelHilfeProps) {
    return (
        <Dialog open={offen} onOpenChange={onOffenChange}>
            <DialogContent className="sm:max-w-md" data-slot="mailbox-kuerzel-hilfe">
                <DialogHeader>
                    <DialogTitle>{germanLabels.kuerzel.titel}</DialogTitle>
                    <DialogDescription>{germanLabels.kuerzel.untertitel}</DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    {MAILBOX_KUERZEL.map(({ taste, bedeutung }) => (
                        <Fragment key={taste}>
                            <kbd className="rounded border bg-muted px-1.5 font-mono">{taste}</kbd>
                            <span>{bedeutung}</span>
                        </Fragment>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default MailboxKuerzelHilfe;
