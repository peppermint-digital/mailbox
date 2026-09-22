import { Copy, Mail, Search } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Badge } from './ui/badge';
import { Card, CardContent } from './ui/card';
import { Input } from './ui/input';
import { Button } from './ui/button';

/**
 * The address book: everyone a product knows an e-mail address for, searchable.
 *
 * ## Two pools, on purpose
 *
 * The **local** pool arrives once and is filtered in the browser — it is the
 * set a product can hand over cheaply (its own contacts, colleagues, recently
 * used recipients). The **external** pool is asked for per query, because it
 * reaches into other systems and nobody wants that on every keystroke.
 *
 * Both are optional. A product with only contacts passes only `local`; the
 * component then simply never asks anyone else, and the hint below the empty
 * list says so. That is the difference between "this product has less" and
 * "this product is broken", and it must be visible.
 *
 * ## Why the de-duplication runs on the local pool
 *
 * A person can sit in both pools — the address book AND the list of recent
 * recipients. Shown twice, the reader has to work out that it is one person.
 * The local entry wins because it is the maintained one: it carries the name
 * somebody typed, not the name that happened to be in a From header.
 *
 * ## What stays with the product
 *
 * Where the entries come from (`source`), what the source badges are called,
 * and what happens on "write an e-mail". The package does not know your routes
 * and does not guess them.
 */
export interface AddressBookEntry {
    email: string;
    name?: string;
    /** Where this entry came from — the product names its own sources. */
    source?: string;
    company?: string | null;
}

export interface MailAddressBookLabels {
    heading: string;
    /** One line under the heading: which pools this product actually has. */
    description: string;
    back: string;
    searchPlaceholder: string;
    copy: string;
    copied: string;
    copyFailed: string;
    compose: string;
    searching: string;
    /** No hits for something that was typed. */
    noResults: (query: string) => string;
    /** Nothing typed yet and no local pool to show. */
    prompt: string;
    /** Names a source badge. Unknown sources should still get a word. */
    source: (source: string | undefined) => string;
}

export interface MailAddressBookSource {
    /** The cheap pool, fetched once. Omit if the product has none. */
    local?: (signal: AbortSignal) => Promise<AddressBookEntry[]>;
    /** The expensive pool, asked per query. Omit if the product has none. */
    external?: (query: string, signal: AbortSignal) => Promise<AddressBookEntry[]>;
    /** How many characters before `external` is asked. Default 2. */
    minQuery?: number;
}

export interface MailAddressBookProps {
    labels: MailAddressBookLabels;
    source: MailAddressBookSource;
    onCompose: (entry: AddressBookEntry) => void;
    onBack?: () => void;
    /** Told what happened after a copy, so the product can show its own toast. */
    onCopied?: (ok: boolean, message: string) => void;
    /** How a source badge should look. Defaults to a neutral outline. */
    badgeVariant?: (source: string | undefined) => 'default' | 'secondary' | 'outline';
    /** The product's own controls, next to "back". */
    extras?: ReactNode;
}

export function MailAddressBook({ labels, source, onCompose, onBack, onCopied, badgeVariant, extras }: MailAddressBookProps) {
    const [query, setQuery] = useState('');
    const [lokal, setLokal] = useState<AddressBookEntry[]>([]);
    const [extern, setExtern] = useState<AddressBookEntry[]>([]);
    const [suchtExtern, setSuchtExtern] = useState(false);

    const mindestens = source.minQuery ?? 2;
    const holeLokal = source.local;
    const holeExtern = source.external;

    useEffect(() => {
        if (!holeLokal) {
            return;
        }

        const abbruch = new AbortController();

        void (async () => {
            try {
                setLokal(await holeLokal(abbruch.signal));
            } catch {
                // Der lokale Vorrat bleibt leer. Die Suche in den anderen
                // Verzeichnissen funktioniert trotzdem — ein Ausfall auf der
                // einen Seite darf die andere nicht mitnehmen.
            }
        })();

        return () => abbruch.abort();
    }, [holeLokal]);

    /*
     * Verzoegerte Suche. Zeitgeber UND Anfrage haengen in der Aufraeumfunktion:
     * Ohne das ueberholt beim schnellen Tippen eine aeltere Antwort die
     * juengere, und in der Liste steht das Ergebnis zu einem Suchbegriff, der
     * im Feld gar nicht mehr steht.
     */
    useEffect(() => {
        const q = query.trim();

        if (!holeExtern || q.length < mindestens) {
            setExtern([]);
            setSuchtExtern(false);

            return;
        }

        setSuchtExtern(true);
        const abbruch = new AbortController();

        const uhr = setTimeout(async () => {
            try {
                setExtern(await holeExtern(q, abbruch.signal));
                setSuchtExtern(false);
            } catch {
                if (!abbruch.signal.aborted) {
                    setExtern([]);
                    setSuchtExtern(false);
                }
            }
        }, 300);

        return () => {
            clearTimeout(uhr);
            abbruch.abort();
        };
    }, [query, holeExtern, mindestens]);

    const treffer = useMemo<AddressBookEntry[]>(() => {
        const q = query.trim().toLowerCase();
        const passt = (c: AddressBookEntry) => !q || c.email.toLowerCase().includes(q) || (c.name ?? '').toLowerCase().includes(q);

        const gefiltert = lokal.filter(passt);
        const gesehen = new Set(gefiltert.map((c) => c.email.toLowerCase()));

        return [...gefiltert, ...extern.filter((c) => !gesehen.has(c.email.toLowerCase()))];
    }, [query, lokal, extern]);

    const kopiere = async (eintrag: AddressBookEntry) => {
        try {
            await navigator.clipboard.writeText(eintrag.email);
            onCopied?.(true, labels.copied);
        } catch {
            onCopied?.(false, labels.copyFailed);
        }
    };

    return (
        <div className="flex h-full flex-col gap-4 p-4" data-slot="mail-address-book">
            <div className="flex items-center justify-between gap-2">
                <div>
                    <h1 className="text-xl font-semibold">{labels.heading}</h1>
                    <p className="text-sm text-muted-foreground">{labels.description}</p>
                </div>
                <div className="flex items-center gap-2">
                    {extras}
                    {onBack && (
                        <Button variant="outline" size="sm" onClick={onBack}>
                            {labels.back}
                        </Button>
                    )}
                </div>
            </div>

            <div className="relative">
                <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    value={query}
                    placeholder={labels.searchPlaceholder}
                    className="pl-9"
                    autoFocus
                    aria-label={labels.searchPlaceholder}
                    onChange={(e) => setQuery(e.target.value)}
                />
            </div>

            <Card className="flex-1 overflow-hidden">
                <CardContent className="p-0">
                    {treffer.length > 0 ? (
                        <div className="divide-y">
                            {treffer.map((c) => (
                                <div
                                    key={`${c.source ?? ''}:${c.email}`}
                                    className="flex items-center gap-3 px-4 py-2.5 hover:bg-muted/50"
                                    data-slot="mail-address-book-row"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            {c.name && <span className="truncate text-sm font-medium">{c.name}</span>}
                                            <Badge variant={badgeVariant?.(c.source) ?? 'outline'} className="shrink-0 text-[10px]">
                                                {labels.source(c.source)}
                                            </Badge>
                                        </div>
                                        <div className="truncate text-xs text-muted-foreground">
                                            {c.email}
                                            {c.company ? ` · ${c.company}` : ''}
                                        </div>
                                    </div>
                                    <Button variant="ghost" size="icon" title={labels.copy} aria-label={labels.copy} onClick={() => void kopiere(c)}>
                                        <Copy className="h-4 w-4" />
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => onCompose(c)}>
                                        <Mail className="mr-1 h-4 w-4" /> {labels.compose}
                                    </Button>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="flex flex-col items-center justify-center gap-2 px-4 py-16 text-center text-sm text-muted-foreground">
                            <Search className="h-8 w-8 opacity-40" />
                            {suchtExtern ? (
                                <p>{labels.searching}</p>
                            ) : query.trim().length >= mindestens ? (
                                <p>{labels.noResults(query.trim())}</p>
                            ) : (
                                <p>{labels.prompt}</p>
                            )}
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
