import { BookUser, PenSquare, RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from './ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';

/**
 * The row above the mailbox: what it is called, which mailbox is open, and the
 * handful of things one does to a mailbox as a whole.
 *
 * ## Why this is in the package
 *
 * Same reason as `MailListToolbar`, one row higher. Until 22.09.2026 the
 * project manager's header carried a shadcn select, an address book, a compose
 * button and a refresh button; the CRM and the Verwaltung had a bare `<select>`
 * and nothing else. Opening "the mailbox" in two products showed two different
 * things — and the difference was not a decision anyone had made, it was just
 * where the code happened to have been written.
 *
 * ## The rule this follows
 *
 * Install the package anywhere and it looks the same. What a product has ON TOP
 * appears additionally, in its own place — never by quietly changing the shared
 * part. That is what `extras` is: a named area after the common buttons, so a
 * reader can see at a glance which controls are everyone's and which are this
 * product's.
 *
 * A button whose callback is missing is not rendered. A product without a
 * compose route should not show a compose button that does nothing — that is
 * worse than not having one, because it looks like a defect rather than an
 * absence.
 */
export interface MailboxHeaderLabels {
    addressBook: string;
    compose: string;
    refresh: string;
    refreshing: string;
    /** Accessible name of the mailbox picker. */
    accountPicker: string;
    /** Shown in the picker when the product has no mailbox at all. */
    noAccounts: string;
}

export interface MailboxHeaderAccount {
    id: number | string;
    /** What the picker shows. Products decide: label, address, or both. */
    name: string;
}

/**
 * Anything a product wants to say about one mailbox in the picker — the
 * project manager marks shared mailboxes with a globe, nobody else has the
 * concept. Same rule as `extras`, one level down: the shared shape stays, the
 * product decorates its own rows.
 */
export type MailboxHeaderAccessory = (account: MailboxHeaderAccount) => ReactNode;

export interface MailboxHeaderProps {
    /** The page's own name — "E-Mails" here, "E-Mail Browser" there. */
    title: string;
    labels: MailboxHeaderLabels;

    accounts: MailboxHeaderAccount[];
    accountId: number | string | null;
    onAccountChange: (id: string) => void;

    /** Each button appears only if the product answers for it. */
    onAddressBook?: () => void;
    onCompose?: () => void;
    onRefresh?: () => void;
    refreshing?: boolean;

    /** A problem worth showing next to the title. */
    error?: ReactNode;

    /** The product's own tools, in their own area after the shared ones. */
    extras?: ReactNode;

    /** Decorates one row of the mailbox picker. */
    accountAccessory?: MailboxHeaderAccessory;
}

export function MailboxHeader({
    title,
    labels,
    accounts,
    accountId,
    onAccountChange,
    onAddressBook,
    onCompose,
    onRefresh,
    refreshing = false,
    error,
    extras,
    accountAccessory,
}: MailboxHeaderProps) {
    return (
        <div className="flex items-center justify-between gap-4 border-b px-6 py-4" data-slot="mailbox-header">
            <div className="flex min-w-0 items-center gap-4">
                <h1 className="shrink-0 text-2xl font-bold">{title}</h1>

                <Select value={accountId === null ? '' : String(accountId)} onValueChange={onAccountChange} disabled={accounts.length === 0}>
                    <SelectTrigger className="w-[250px] [&>span]:min-w-0" aria-label={labels.accountPicker}>
                        <SelectValue placeholder={accounts.length === 0 ? labels.noAccounts : labels.accountPicker} />
                    </SelectTrigger>
                    <SelectContent>
                        {accounts.map((konto) => (
                            <SelectItem key={konto.id} value={String(konto.id)}>
                                <div className="flex min-w-0 items-center gap-2">
                                    {accountAccessory?.(konto)}
                                    <span className="truncate" title={konto.name}>
                                        {konto.name}
                                    </span>
                                </div>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {error && (
                    <span className="truncate text-sm text-destructive" data-slot="mailbox-header-error">
                        {error}
                    </span>
                )}
            </div>

            <div className="flex shrink-0 items-center gap-3">
                {onAddressBook && (
                    <Button variant="outline" size="icon" title={labels.addressBook} aria-label={labels.addressBook} onClick={onAddressBook}>
                        <BookUser className="h-4 w-4" />
                    </Button>
                )}

                {onCompose && (
                    <Button onClick={onCompose}>
                        <PenSquare className="mr-2 h-4 w-4" />
                        {labels.compose}
                    </Button>
                )}

                {onRefresh && (
                    <Button
                        variant="outline"
                        size="icon"
                        onClick={onRefresh}
                        disabled={refreshing}
                        title={refreshing ? labels.refreshing : labels.refresh}
                        aria-label={labels.refresh}
                    >
                        <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
                    </Button>
                )}

                {/* Die produkteigenen Werkzeuge, sichtbar als solche: hinter den
                    gemeinsamen und in einem eigenen Bereich. Wer die Kopfzeile
                    zweier Produkte nebeneinanderlegt, sieht sofort, was geteilt
                    ist und was nicht. */}
                {extras && (
                    <div className="flex items-center gap-2 border-l pl-3" data-slot="mailbox-header-extras">
                        {extras}
                    </div>
                )}
            </div>
        </div>
    );
}
