import { UserPlus, X } from 'lucide-react';
import type { MailAssignee, MailAssignment } from './useMailAssignments';
import { Button } from './ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from './ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from './ui/tooltip';

/**
 * Das Kennzeichen an der Zeile — und die Auswahl dahinter.
 *
 * ## Zwei Zustaende, ein Platz
 *
 * Ist niemand zustaendig, steht dort ein blasses Personensymbol; es faellt
 * nicht auf, laedt aber zum Klick ein. Ist jemand zustaendig, stehen seine
 * Initialen da — farbig, und beim Ueberfahren der ganze Name.
 *
 * Beides an derselben Stelle: Wer eine Liste ueberfliegt, soll an einer
 * einzigen Spalte ablesen koennen, was offen ist. Zwei verschiedene Orte fuer
 * „niemand" und „jemand" zwingen zum Suchen.
 *
 * ## Warum kein Name in der Zeile
 *
 * Die Spalte ist 320 px breit und traegt schon Absender, Betreff und Datum.
 * „AM" kostet zwei Zeichen, „Anna Meier" kostet den halben Betreff. Der volle
 * Name steht im Popover.
 */
export interface MailAssignmentPickerLabels {
    assign: string;
    /** Steht im Popover ueber den Initialen. */
    assignedTo: (name: string) => string;
    unassign: string;
    /** Wenn das Produkt niemanden nennt, dem zugewiesen werden koennte. */
    noUsers: string;
}

export interface MailAssignmentPickerProps {
    labels: MailAssignmentPickerLabels;
    users: MailAssignee[];
    assignment: MailAssignment | null;
    onAssign: (userId: number) => void;
    onUnassign: () => void;
    busy?: boolean;
}

export function MailAssignmentPicker({ labels, users, assignment, onAssign, onUnassign, busy = false }: MailAssignmentPickerProps) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                {assignment ? (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <button
                                type="button"
                                disabled={busy}
                                className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/15 text-[10px] font-semibold text-primary"
                                data-slot="mail-assignment-badge"
                                onClick={(e) => e.stopPropagation()}
                            >
                                {assignment.initials}
                            </button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">{labels.assignedTo(assignment.name)}</TooltipContent>
                    </Tooltip>
                ) : (
                    <button
                        type="button"
                        disabled={busy}
                        title={labels.assign}
                        aria-label={labels.assign}
                        className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-muted-foreground/40 hover:bg-muted hover:text-foreground"
                        data-slot="mail-assignment-empty"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <UserPlus className="h-3.5 w-3.5" />
                    </button>
                )}
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="max-h-80 overflow-auto" onClick={(e) => e.stopPropagation()}>
                {users.length === 0 ? (
                    <div className="px-2 py-1.5 text-xs text-muted-foreground">{labels.noUsers}</div>
                ) : (
                    users.map((nutzer) => (
                        <DropdownMenuItem key={nutzer.id} onSelect={() => onAssign(nutzer.id)}>
                            <span className="mr-2 flex h-5 w-5 items-center justify-center rounded-full bg-muted text-[10px] font-semibold">
                                {nutzer.initials}
                            </span>
                            {nutzer.name}
                        </DropdownMenuItem>
                    ))
                )}

                {assignment && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={onUnassign}>
                            <X className="mr-2 h-4 w-4" />
                            {labels.unassign}
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
