import {
    Archive,
    ChevronLeft,
    ChevronRight,
    Filter,
    FolderInput,
    FolderOpen,
    Loader2,
    Mail,
    MailOpen,
    MessagesSquare,
    PanelLeft,
    PanelLeftClose,
    Search,
    Trash2,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type { MailFolder } from './MailFolderList';
import type { MessageHandle } from './rows';
import { Button } from './ui/button';
import { Checkbox } from './ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from './ui/dropdown-menu';
import { Input } from './ui/input';
import { Label } from './ui/label';

/**
 * Everything that sits above the message rows: folder toggle, search, filters,
 * the search banner, the count/pagination line and the bulk-action bar.
 *
 * ## Why this is in the package and not in each product
 *
 * Until 22.09.2026 this stack existed once — inline in the project manager's
 * mail page, roughly 300 lines of it. The CRM and the Verwaltung had a plain
 * input and two checkboxes instead. Same package underneath, three different
 * mailboxes on screen: the CRM could not mark a message read in bulk, could
 * not move one to another folder, had no pagination and no empty state.
 *
 * That is the failure this package exists to prevent, one storey up. The
 * verbs were shared (`bulk.ts` has shipped `runBulkAcrossFolders` for weeks);
 * only the controls to reach them were not. So a product that installed the
 * package got the mailbox, but not the mailbox people actually use.
 *
 * ## What stays with the product
 *
 * Words and wiring. Every string arrives through `labels` — the package makes
 * no assumption about language. Every action arrives as a callback — the
 * package does not know what archiving means in your system.
 *
 * Two slots carry what genuinely differs:
 *
 * - `extras` goes between the filter button and the search button. The project
 *   manager puts "only mine" and its keyboard-shortcut help there; nobody else
 *   has either.
 * - `notice` goes under the search banner. The project manager reports how old
 *   its header index is there — it is the only product that keeps one.
 *
 * A slot is the honest way to say "this one is different". Adding a
 * `showAssignedFilter` flag would put a feature two of three products can
 * never use into the shared contract, and the next reader would have to find
 * out why it is always false.
 */
export interface MailListToolbarLabels {
    /** Title of the folder-column toggle, which flips with its state. */
    toggleFolders: (shown: boolean) => string;
    searchPlaceholder: string;
    clearSearch: string;
    filters: string;
    search: string;
    searching: string;
    filterFrom: string;
    filterFromPlaceholder: string;
    filterSubject: string;
    filterSubjectPlaceholder: string;
    filterSince: string;
    filterUnseen: string;
    filterAllFolders: string;
    /** The small print next to "all folders" — which folders stay out. */
    filterAllFoldersHint: string;
    reset: string;
    searchResultsFor: (query: string) => string;
    /** Heading when filters alone produced the hits and no words were typed. */
    searchResultsFiltered: string;
    back: string;
    groupByThread: string;
    /** Why grouping is unavailable while search hits are shown. */
    groupDisabledInSearch: string;
    loading: string;
    /** "42 conversations" / "42 e-mails" — the noun follows the mode. */
    countMessages: (total: number, grouped: boolean) => string;
    /** "12 of 300 hits in 7 folders". */
    countHits: (shown: number, found: number, folders: number) => string;
    selected: (count: number) => string;
    /** A thread action reaches more messages than there are ticks. */
    alsoAffects: (messages: number) => string;
    markRead: string;
    markUnread: string;
    archive: string;
    archiveTitle: (count: number) => string;
    move: string;
    moveTitle: string;
    delete: string;
    clearSelection: string;
}

export interface MailListToolbarSearch {
    /** The text in the search box. */
    query: string;
    from: string;
    subject: string;
    since: string;
    unseen: boolean;
    allFolders: boolean;
    /** False while the criteria would not narrow anything down. */
    canSearch: boolean;
    searching: boolean;
    /** True while hits are shown instead of a folder. */
    isSearchMode: boolean;
    foldersSearched: number;
    totalFound: number;
    set: (field: 'query' | 'from' | 'subject' | 'since' | 'unseen' | 'allFolders', value: string | boolean) => void;
    run: () => void;
    clear: () => void;
}

export interface MailListToolbarProps {
    labels: MailListToolbarLabels;
    search: MailListToolbarSearch;

    /** Folder column visibility. Omit both to hide the toggle entirely. */
    showFolders?: boolean;
    onToggleFolders?: () => void;

    /** How many rows the list currently shows, and where in the pages it is. */
    total: number;
    page: number;
    totalPages: number;
    loading?: boolean;
    onPage?: (page: number) => void;

    grouped?: boolean;
    onToggleGrouped?: () => void;

    /** The ticked rows. Without any, the bulk bar does not appear. */
    selected?: ReadonlySet<MessageHandle>;
    /** Messages the action would really touch — larger than `selected` for threads. */
    affected?: number;
    busy?: boolean;
    onMarkRead?: () => void;
    onMarkUnread?: () => void;
    onArchive?: () => void;
    onDelete?: () => void;
    onClearSelection?: () => void;

    /** Targets for "move to folder". Empty or absent hides the button. */
    moveTargets?: MailFolder[];
    currentFolder?: string;
    onMove?: (path: string) => void;
    /** Called when the move menu opens — products load their folders lazily. */
    onMoveMenuOpen?: () => void;

    /** Between the filter button and the search button. */
    extras?: ReactNode;
    /** Under the search banner. */
    notice?: ReactNode;

    /** Open state of the filter panel, held by the product so it survives. */
    filtersOpen?: boolean;
    onToggleFilters?: () => void;
}

export function MailListToolbar({
    labels,
    search,
    showFolders,
    onToggleFolders,
    total,
    page,
    totalPages,
    loading = false,
    onPage,
    grouped = false,
    onToggleGrouped,
    selected,
    affected,
    busy = false,
    onMarkRead,
    onMarkUnread,
    onArchive,
    onDelete,
    onClearSelection,
    moveTargets,
    currentFolder,
    onMove,
    onMoveMenuOpen,
    extras,
    notice,
    filtersOpen = false,
    onToggleFilters,
}: MailListToolbarProps) {
    const ausgewaehlt = selected?.size ?? 0;
    // Without an explicit count a tick stands for exactly one message. Saying
    // "5 selected · 5 messages" would be noise, so the extra line only shows
    // when a thread actually widens the reach.
    const betroffen = affected ?? ausgewaehlt;
    const suchbereit = search.canSearch && !search.searching;

    return (
        <div data-slot="mail-list-toolbar">
            <div className="flex items-center gap-2 border-b p-2">
                {onToggleFolders && (
                    <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 shrink-0"
                        onClick={onToggleFolders}
                        title={labels.toggleFolders(showFolders !== false)}
                    >
                        {showFolders !== false ? <PanelLeftClose className="h-4 w-4" /> : <PanelLeft className="h-4 w-4" />}
                    </Button>
                )}

                <div className="relative flex-1">
                    <Search className="absolute top-1/2 left-2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search.query}
                        placeholder={labels.searchPlaceholder}
                        className="h-8 pr-8 pl-8 text-sm"
                        aria-label={labels.searchPlaceholder}
                        onChange={(e) => search.set('query', e.target.value)}
                        onKeyUp={(e) => {
                            if (e.key === 'Enter') {
                                search.run();
                            }
                        }}
                    />
                    {(search.query || search.isSearchMode) && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="absolute top-1/2 right-1 h-6 w-6 -translate-y-1/2"
                            title={labels.clearSearch}
                            aria-label={labels.clearSearch}
                            onClick={search.clear}
                        >
                            <X className="h-3 w-3" />
                        </Button>
                    )}
                </div>

                {onToggleFilters && (
                    <Button
                        variant="ghost"
                        size="icon"
                        className={`h-8 w-8 shrink-0 ${filtersOpen ? 'bg-muted' : ''}`}
                        title={labels.filters}
                        aria-label={labels.filters}
                        onClick={onToggleFilters}
                    >
                        <Filter className="h-4 w-4" />
                    </Button>
                )}

                {extras}

                <Button
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 shrink-0"
                    onClick={search.run}
                    disabled={!suchbereit}
                    title={search.searching ? labels.searching : labels.search}
                    aria-label={labels.search}
                >
                    {search.searching ? <Loader2 className="h-4 w-4 animate-spin" /> : <Search className="h-4 w-4" />}
                </Button>
            </div>

            {filtersOpen && (
                <div className="space-y-2 border-b bg-muted/30 p-3" data-slot="mail-list-filters">
                    <div className="grid grid-cols-2 gap-2">
                        <div>
                            <Label className="text-xs text-muted-foreground">{labels.filterFrom}</Label>
                            <Input
                                value={search.from}
                                placeholder={labels.filterFromPlaceholder}
                                className="mt-1 h-8 text-sm"
                                onChange={(e) => search.set('from', e.target.value)}
                                onKeyUp={(e) => {
                                    if (e.key === 'Enter') {
                                        search.run();
                                    }
                                }}
                            />
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">{labels.filterSubject}</Label>
                            <Input
                                value={search.subject}
                                placeholder={labels.filterSubjectPlaceholder}
                                className="mt-1 h-8 text-sm"
                                onChange={(e) => search.set('subject', e.target.value)}
                                onKeyUp={(e) => {
                                    if (e.key === 'Enter') {
                                        search.run();
                                    }
                                }}
                            />
                        </div>
                        <div>
                            <Label className="text-xs text-muted-foreground">{labels.filterSince}</Label>
                            <Input
                                value={search.since}
                                type="date"
                                className="mt-1 h-8 text-sm"
                                aria-label={labels.filterSince}
                                onChange={(e) => search.set('since', e.target.value)}
                            />
                        </div>
                        <div className="flex items-end gap-3 pb-1">
                            <label className="flex cursor-pointer items-center gap-2 text-sm">
                                <Checkbox checked={search.unseen} onCheckedChange={(v) => search.set('unseen', v === true)} />
                                {labels.filterUnseen}
                            </label>
                            <label className="flex cursor-pointer items-center gap-2 text-sm">
                                <Checkbox checked={search.allFolders} onCheckedChange={(v) => search.set('allFolders', v === true)} />
                                {labels.filterAllFolders}
                                <span className="text-xs text-muted-foreground">{labels.filterAllFoldersHint}</span>
                            </label>
                        </div>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button variant="ghost" size="sm" onClick={search.clear}>
                            {labels.reset}
                        </Button>
                        <Button size="sm" disabled={!suchbereit} onClick={search.run}>
                            {search.searching && <Loader2 className="mr-2 h-3.5 w-3.5 animate-spin" />}
                            {labels.search}
                        </Button>
                    </div>
                </div>
            )}

            {search.isSearchMode && (
                <div className="flex items-center justify-between bg-primary/10 px-3 py-1.5 text-xs" data-slot="mail-list-search-banner">
                    <span>{search.query ? labels.searchResultsFor(search.query) : labels.searchResultsFiltered}</span>
                    <Button variant="ghost" size="sm" className="h-5 px-2 text-xs" onClick={search.clear}>
                        {labels.back}
                    </Button>
                </div>
            )}

            {notice}

            <div className="flex items-center justify-between border-b p-2 text-sm text-muted-foreground">
                <div className="flex items-center gap-1">
                    {onToggleGrouped && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className={`h-6 w-6 ${grouped ? 'bg-muted text-foreground' : ''}`}
                            disabled={search.isSearchMode}
                            title={search.isSearchMode ? labels.groupDisabledInSearch : labels.groupByThread}
                            aria-label={labels.groupByThread}
                            onClick={onToggleGrouped}
                        >
                            <MessagesSquare className="h-4 w-4" />
                        </Button>
                    )}
                    {loading || search.searching ? (
                        <span className="flex items-center gap-2">
                            <Loader2 className="h-3 w-3 animate-spin" />
                            {labels.loading}
                        </span>
                    ) : search.isSearchMode ? (
                        <span>{labels.countHits(total, search.totalFound, search.foldersSearched)}</span>
                    ) : (
                        <span>{labels.countMessages(total, grouped)}</span>
                    )}
                </div>

                {/* Hits span folders, so there are no pages to leaf through. */}
                {totalPages > 1 && !search.isSearchMode && onPage && (
                    <div className="flex items-center gap-1" data-slot="mail-list-pagination">
                        <Button variant="ghost" size="icon" className="h-6 w-6" disabled={page <= 1} onClick={() => onPage(page - 1)}>
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <span className="text-xs">
                            {page} / {totalPages}
                        </span>
                        <Button variant="ghost" size="icon" className="h-6 w-6" disabled={page >= totalPages} onClick={() => onPage(page + 1)}>
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                )}
            </div>

            {ausgewaehlt > 0 && (
                <div className="flex flex-wrap items-center gap-1 border-b bg-primary/5 p-2 text-sm" data-slot="mail-list-bulk">
                    <span className="mr-1 font-medium">
                        {labels.selected(ausgewaehlt)}
                        {betroffen > ausgewaehlt && <span className="font-normal text-muted-foreground"> · {labels.alsoAffects(betroffen)}</span>}
                    </span>

                    {onMarkRead && (
                        <Button variant="ghost" size="sm" disabled={busy} title={labels.markRead} onClick={onMarkRead}>
                            <MailOpen className="mr-1 h-3.5 w-3.5" />
                            {labels.markRead}
                        </Button>
                    )}
                    {onMarkUnread && (
                        <Button variant="ghost" size="sm" disabled={busy} title={labels.markUnread} onClick={onMarkUnread}>
                            <Mail className="mr-1 h-3.5 w-3.5" />
                            {labels.markUnread}
                        </Button>
                    )}
                    {onArchive && (
                        <Button variant="ghost" size="sm" disabled={busy} title={labels.archiveTitle(betroffen)} onClick={onArchive}>
                            <Archive className="mr-1 h-3.5 w-3.5" />
                            {labels.archive}
                        </Button>
                    )}
                    {onMove && (
                        <DropdownMenu onOpenChange={(offen) => offen && onMoveMenuOpen?.()}>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="sm" disabled={busy} title={labels.moveTitle}>
                                    <FolderInput className="mr-1 h-3.5 w-3.5" />
                                    {labels.move}
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start" className="max-h-80 overflow-auto">
                                {(moveTargets ?? []).map((f) => (
                                    <DropdownMenuItem key={f.path} disabled={f.path === currentFolder} onSelect={() => onMove(f.path)}>
                                        <FolderOpen className="mr-2 h-4 w-4" />
                                        {f.name}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                    {onDelete && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            disabled={busy}
                            title={labels.delete}
                            onClick={onDelete}
                        >
                            <Trash2 className="mr-1 h-3.5 w-3.5" />
                            {labels.delete}
                        </Button>
                    )}
                    {onClearSelection && (
                        <Button variant="ghost" size="sm" className="ml-auto" title={labels.clearSelection} aria-label={labels.clearSelection} onClick={onClearSelection}>
                            <X className="h-4 w-4" />
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
