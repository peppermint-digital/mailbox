import { FolderOpen, FolderPlus, Inbox } from 'lucide-react';
import type { MouseEvent } from 'react';
import { cn } from './ui/utils';

/**
 * The folder column of a mailbox.
 *
 * ## INBOX gets its own icon, and it is not decoration
 *
 * Every mailbox has exactly one INBOX and it is where mail arrives. Giving it
 * the same folder icon as "Projekte/2026" makes the one folder people look for
 * first indistinguishable from the twenty they made themselves.
 *
 * ## What the product decides
 *
 * Which folders are shown at all. This list renders what it is handed — the
 * product has already dropped the ones it does not want (an outbox that is
 * always empty on IMAP, say), because that judgement needs its endpoint and
 * its settings, not a rule.
 */

export interface MailFolder {
    name: string;
    path: string;
    flags?: string[] | null;
}

export interface MailFolderListLabels {
    /** Heading above the list. */
    heading: string;
    /** Title of the button that creates a folder. */
    createFolder: string;
}

export interface MailFolderListProps {
    folders: MailFolder[];
    labels: MailFolderListLabels;
    /** The folder currently shown. */
    selectedPath?: string;
    loading?: boolean;
    onSelect: (path: string) => void;
    /** Omit to hide the create button — not every product lets people add folders. */
    onCreate?: () => void;
    /** The product's own folder menu, e.g. right-click to rename. */
    onFolderContextMenu?: (event: MouseEvent<HTMLElement>, folder: MailFolder) => void;
    /** Skeletons while loading; the product brings its own so they match its list. */
    loadingPlaceholder?: React.ReactNode;
}

export function MailFolderList({
    folders,
    labels,
    selectedPath,
    loading = false,
    onSelect,
    onCreate,
    onFolderContextMenu,
    loadingPlaceholder,
}: MailFolderListProps) {
    if (loading) {
        return (
            <div className="space-y-2 p-2" data-slot="mail-folder-loading">
                {loadingPlaceholder}
            </div>
        );
    }

    return (
        <div className="space-y-1" data-slot="mail-folder-list">
            <div className="mb-1 flex items-center justify-between px-2">
                <span className="text-xs font-medium text-muted-foreground">{labels.heading}</span>
                {onCreate && (
                    <button
                        type="button"
                        className="rounded p-1 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        title={labels.createFolder}
                        onClick={onCreate}
                    >
                        <FolderPlus className="h-4 w-4" />
                    </button>
                )}
            </div>

            {folders.map((folder) => (
                <button
                    key={folder.path}
                    type="button"
                    data-slot="mail-folder"
                    data-selected={selectedPath === folder.path ? '' : undefined}
                    onClick={() => onSelect(folder.path)}
                    onContextMenu={(event) => {
                        event.preventDefault();
                        onFolderContextMenu?.(event, folder);
                    }}
                    className={cn(
                        'flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm transition-colors',
                        selectedPath === folder.path ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                    )}
                >
                    {folder.path.toUpperCase() === 'INBOX' ? <Inbox className="h-4 w-4" /> : <FolderOpen className="h-4 w-4" />}
                    <span className="truncate">{folder.name}</span>
                </button>
            ))}
        </div>
    );
}

export default MailFolderList;
