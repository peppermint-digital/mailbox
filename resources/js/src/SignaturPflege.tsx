import { useCallback, useEffect, useState, type ComponentType, type FormEvent } from 'react';
import { germanLabels } from './germanLabels';
import { RichTextEditor, type RichTextEditorProps } from './RichTextEditor';
import { Badge, Button, Checkbox, Input, Label, Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from './ui/dialog';

/**
 * Die eigenen Signaturen pflegen — die Flaeche zu `Http\PflegtSignaturen`.
 *
 * Fuer ein Produkt, das seine Signaturen selbst fuehrt
 * (`Stores\LokalerSignaturvorrat`). Das Produkt gibt die Route, das Paket die
 * Maske: Jede Person sieht und aendert nur ihre eigenen, und ein Postfach
 * steht nur zur Wahl, wenn sie es sehen darf — das entscheidet der Server,
 * die Maske zeigt es bloss an.
 *
 * Gefragt wird mit `fetch` gegen `{basis}` (Liste, Anlegen) und
 * `{basis}/{id}` (Aendern, Loeschen).
 */

export type SignaturPflegeLabels = typeof germanLabels.signaturPflege;

export interface GepflegteSignatur {
    id: number;
    name: string;
    body_html: string;
    is_default: boolean;
    mail_account_id: number | null;
}

export interface SignaturPostfach {
    id: number;
    email: string;
    label: string;
}

export interface SignaturPflegeProps {
    /** Die Route des Produkts, z. B. `/emails/signaturen`. */
    basis: string;
    labels?: SignaturPflegeLabels;
    /** Der Editor fuer den Rumpf. Ohne Angabe der des Pakets. */
    editor?: ComponentType<RichTextEditorProps>;
    /** Wie Bilder hochgeladen werden — ohne Angabe bietet der Editor keine an. */
    onImageUpload?: (file: File) => Promise<string>;
    csrfToken?: () => string;
    fetch?: typeof globalThis.fetch;
}

interface Entwurf {
    id: number | null;
    name: string;
    body_html: string;
    is_default: boolean;
    mail_account_id: number | null;
}

const ALLE = 'alle';

function metaCsrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function leererEntwurf(): Entwurf {
    return { id: null, name: '', body_html: '', is_default: false, mail_account_id: null };
}

export function SignaturPflege({
    basis,
    labels = germanLabels.signaturPflege,
    editor: Editor = RichTextEditor,
    onImageUpload,
    csrfToken = metaCsrf,
    fetch: eigenesFetch,
}: SignaturPflegeProps) {
    const holen = eigenesFetch ?? globalThis.fetch;
    const [signaturen, setSignaturen] = useState<GepflegteSignatur[]>([]);
    const [postfaecher, setPostfaecher] = useState<SignaturPostfach[]>([]);
    const [laedt, setLaedt] = useState(true);
    const [ladefehler, setLadefehler] = useState(false);
    const [entwurf, setEntwurf] = useState<Entwurf | null>(null);
    const [fehler, setFehler] = useState<Record<string, string>>({});
    const [speichert, setSpeichert] = useState(false);
    const [loeschziel, setLoeschziel] = useState<GepflegteSignatur | null>(null);
    const [meldung, setMeldung] = useState<string | null>(null);

    const kopf = useCallback(
        (): Record<string, string> => ({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        }),
        [csrfToken],
    );

    const laden = useCallback(async () => {
        setLaedt(true);
        setLadefehler(false);

        try {
            const antwort = await holen(basis, { headers: kopf(), credentials: 'same-origin' });

            if (!antwort.ok) {
                throw new Error(String(antwort.status));
            }

            const daten = (await antwort.json()) as { signatures?: GepflegteSignatur[]; accounts?: SignaturPostfach[] };
            setSignaturen(daten.signatures ?? []);
            setPostfaecher(daten.accounts ?? []);
        } catch {
            setLadefehler(true);
        } finally {
            setLaedt(false);
        }
    }, [basis, holen, kopf]);

    useEffect(() => {
        void laden();
    }, [laden]);

    const postfachName = (kennung: number | null): string => {
        if (kennung === null) {
            return labels.allAccounts;
        }

        return postfaecher.find((p) => p.id === kennung)?.label ?? labels.unknownAccount;
    };

    const speichern = async (ereignis: FormEvent) => {
        ereignis.preventDefault();

        if (!entwurf) {
            return;
        }

        setSpeichert(true);
        setFehler({});
        setMeldung(null);

        try {
            const antwort = await holen(entwurf.id === null ? basis : `${basis}/${entwurf.id}`, {
                method: entwurf.id === null ? 'POST' : 'PATCH',
                headers: kopf(),
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: entwurf.name,
                    body_html: entwurf.body_html,
                    is_default: entwurf.is_default,
                    mail_account_id: entwurf.mail_account_id,
                }),
            });

            if (antwort.status === 422) {
                const daten = (await antwort.json()) as { errors?: Record<string, string[]> };
                setFehler(Object.fromEntries(Object.entries(daten.errors ?? {}).map(([feld, texte]) => [feld, texte[0] ?? ''])));

                return;
            }

            if (!antwort.ok) {
                setMeldung(labels.saveFailed);

                return;
            }

            setEntwurf(null);
            await laden();
        } catch {
            setMeldung(labels.saveFailed);
        } finally {
            setSpeichert(false);
        }
    };

    const loeschen = async () => {
        if (!loeschziel) {
            return;
        }

        const ziel = loeschziel;
        setLoeschziel(null);
        setMeldung(null);

        try {
            const antwort = await holen(`${basis}/${ziel.id}`, { method: 'DELETE', headers: kopf(), credentials: 'same-origin' });

            if (!antwort.ok) {
                setMeldung(labels.deleteFailed);

                return;
            }

            await laden();
        } catch {
            setMeldung(labels.deleteFailed);
        }
    };

    return (
        <div className="space-y-4" data-slot="signatur-pflege">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="space-y-1">
                    <h2 className="text-base font-medium">{labels.title}</h2>
                    <p className="text-sm text-muted-foreground">{labels.description}</p>
                </div>
                {entwurf === null && (
                    <Button
                        type="button"
                        onClick={() => {
                            setFehler({});
                            setEntwurf(leererEntwurf());
                        }}
                    >
                        {labels.add}
                    </Button>
                )}
            </div>

            {meldung && (
                <p role="alert" className="text-sm text-destructive">
                    {meldung}
                </p>
            )}

            {entwurf !== null && (
                <form onSubmit={speichern} className="space-y-4 rounded-lg border p-4" aria-label={labels.title}>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="signatur-name">{labels.name}</Label>
                            <Input
                                id="signatur-name"
                                value={entwurf.name}
                                placeholder={labels.namePlaceholder}
                                onChange={(e) => setEntwurf({ ...entwurf, name: e.target.value })}
                            />
                            {fehler.name && <p className="text-sm text-destructive">{fehler.name}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="signatur-postfach">{labels.account}</Label>
                            <Select
                                value={entwurf.mail_account_id === null ? ALLE : String(entwurf.mail_account_id)}
                                onValueChange={(wert) => setEntwurf({ ...entwurf, mail_account_id: wert === ALLE ? null : Number(wert) })}
                            >
                                <SelectTrigger id="signatur-postfach" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALLE}>{labels.allAccounts}</SelectItem>
                                    {/* Ein unsichtbar gewordenes Postfach bleibt waehlbar, solange die Signatur daran haengt. */}
                                    {entwurf.mail_account_id !== null && !postfaecher.some((p) => p.id === entwurf.mail_account_id) && (
                                        <SelectItem value={String(entwurf.mail_account_id)}>{labels.unknownAccount}</SelectItem>
                                    )}
                                    {postfaecher.map((postfach) => (
                                        <SelectItem key={postfach.id} value={String(postfach.id)}>
                                            {postfach.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {fehler.mail_account_id && <p className="text-sm text-destructive">{fehler.mail_account_id}</p>}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label>{labels.body}</Label>
                        <Editor
                            value={entwurf.body_html}
                            onChange={(html) => setEntwurf((vorher) => (vorher ? { ...vorher, body_html: html } : vorher))}
                            labels={germanLabels.editor}
                            minHeight={140}
                            onImageUpload={onImageUpload}
                        />
                        {fehler.body_html && <p className="text-sm text-destructive">{fehler.body_html}</p>}
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="signatur-vorgabe"
                            checked={entwurf.is_default}
                            onCheckedChange={(wert) => setEntwurf({ ...entwurf, is_default: wert === true })}
                        />
                        <Label htmlFor="signatur-vorgabe">{labels.isDefault}</Label>
                    </div>

                    <div className="flex gap-2">
                        <Button type="submit" disabled={speichert}>
                            {speichert ? labels.saving : labels.save}
                        </Button>
                        <Button type="button" variant="outline" onClick={() => setEntwurf(null)} disabled={speichert}>
                            {labels.cancel}
                        </Button>
                    </div>
                </form>
            )}

            {laedt ? (
                <p className="text-sm text-muted-foreground">{labels.loading}</p>
            ) : ladefehler ? (
                <p role="alert" className="text-sm text-destructive">
                    {labels.loadFailed}
                </p>
            ) : signaturen.length === 0 ? (
                <p className="text-sm text-muted-foreground">{labels.empty}</p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {signaturen.map((signatur) => (
                        <li key={signatur.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
                            <div className="min-w-0 space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">{signatur.name}</span>
                                    {signatur.is_default && <Badge variant="secondary">{labels.defaultBadge}</Badge>}
                                </div>
                                <p className="text-sm text-muted-foreground">{postfachName(signatur.mail_account_id)}</p>
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setFehler({});
                                        setEntwurf({ ...signatur });
                                    }}
                                >
                                    {labels.edit}
                                </Button>
                                <Button type="button" variant="outline" size="sm" onClick={() => setLoeschziel(signatur)}>
                                    {labels.delete}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <Dialog open={loeschziel !== null} onOpenChange={(offen) => !offen && setLoeschziel(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{labels.deleteTitle}</DialogTitle>
                        <DialogDescription>{loeschziel ? labels.deleteText(loeschziel.name) : ''}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setLoeschziel(null)}>
                            {labels.cancel}
                        </Button>
                        <Button type="button" variant="destructive" onClick={() => void loeschen()}>
                            {labels.delete}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export default SignaturPflege;
