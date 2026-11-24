import { useCallback, useState, type FormEvent } from 'react';
import { germanLabels } from './germanLabels';
import { Button, Checkbox, Input, Label, Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from './ui/dialog';

/**
 * Ein Postfach anlegen — die Flaeche zu `Http\LegtKontenAn`.
 *
 * Fuer ein Produkt, das seine Postfaecher selbst fuehrt (`store=local`). Das
 * Produkt gibt die Route (`basis`) und sagt, welche Anbieter eingerichtet sind
 * und ob die Person teilen darf; die Regeln stehen auf dem Server, die Maske
 * zeigt sie bloss an.
 *
 * Zwei Wege:
 * - **Microsoft / Google:** Die Seite geht zum Anbieter (`{basis}/oauth/{anbieter}`)
 *   und kommt ueber den Rueckweg des Produkts zurueck.
 * - **Kennwort:** IMAP oder JMAP, dazu wahlweise der Postausgang. Gespeichert
 *   wird erst nach bestandenem Verbindungstest — der Server prueft noch einmal,
 *   der Knopf „Verbindung testen" ist nur der Blick vorher.
 */

export type PostfachAnlegenLabels = typeof germanLabels.postfachAnlegen;

export interface AnlegbarePerson {
    id: number;
    name: string;
}

export interface AngelegtesPostfach {
    id: number;
    email: string;
    label: string;
}

export interface PostfachAnlegenProps {
    /** Die Route des Produkts, z. B. `/settings/email-accounts`. */
    basis: string;
    /** Die eingerichteten Anbieter (`microsoft`, `google`). */
    anbieter?: string[];
    /** Darf die Person ein Gruppenpostfach samt Leseliste anlegen? */
    darfTeilen?: boolean;
    /** Wer auf eine Leseliste kann — nur mit `darfTeilen` gebraucht. */
    personen?: AnlegbarePerson[];
    /** Nach dem Anlegen eines Kennwort-Postfachs, z. B. um die Liste neu zu laden. */
    onAngelegt?: (postfach: AngelegtesPostfach) => void;
    /** Wie zum Anbieter gewechselt wird. Ohne Angabe `window.location.assign`. */
    navigieren?: (adresse: string) => void;
    labels?: PostfachAnlegenLabels;
    csrfToken?: () => string;
    fetch?: typeof globalThis.fetch;
}

interface Formular {
    name: string;
    email: string;
    protocol: 'imap' | 'jmap';
    host: string;
    port: string;
    encryption: string;
    username: string;
    password: string;
    jmap_url: string;
    smtp_host: string;
    smtp_port: string;
    smtp_encryption: string;
}

interface Pruefung {
    ok: boolean;
    message: string;
}

const LEER: Formular = {
    name: '',
    email: '',
    protocol: 'imap',
    host: '',
    port: '993',
    encryption: 'ssl',
    username: '',
    password: '',
    jmap_url: '',
    smtp_host: '',
    smtp_port: '587',
    smtp_encryption: 'tls',
};

const VERSCHLUESSELUNGEN = ['ssl', 'tls', 'starttls', 'none'] as const;

function metaCsrf(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

/** Die Adresse, unter der ein Postfach beim Anbieter (neu) angemeldet wird. */
export function postfachVerbindenAdresse(
    basis: string,
    anbieter: string,
    angaben: { konto?: number; name?: string; geteilt?: boolean } = {},
): string {
    const abfrage = new URLSearchParams();

    if (angaben.konto !== undefined) {
        abfrage.set('konto', String(angaben.konto));
    }
    if (angaben.name) {
        abfrage.set('name', angaben.name);
    }
    if (angaben.geteilt) {
        abfrage.set('geteilt', '1');
    }

    const rest = abfrage.toString();

    return `${basis.replace(/\/+$/, '')}/oauth/${encodeURIComponent(anbieter)}${rest ? `?${rest}` : ''}`;
}

function standardNavigieren(adresse: string): void {
    window.location.assign(adresse);
}

export function PostfachAnlegen({
    basis,
    anbieter = [],
    darfTeilen = false,
    personen = [],
    onAngelegt,
    navigieren = standardNavigieren,
    labels = germanLabels.postfachAnlegen,
    csrfToken = metaCsrf,
    fetch: eigenesFetch,
}: PostfachAnlegenProps) {
    const holen = eigenesFetch ?? globalThis.fetch;
    const [offen, setOffen] = useState(false);
    const [weg, setWeg] = useState<'wahl' | 'kennwort'>('wahl');
    const [formular, setFormular] = useState<Formular>(LEER);
    const [geteilt, setGeteilt] = useState(false);
    const [leser, setLeser] = useState<number[]>([]);
    const [fehler, setFehler] = useState<Record<string, string>>({});
    const [pruefung, setPruefung] = useState<Pruefung | null>(null);
    const [arbeitet, setArbeitet] = useState<'pruefen' | 'anlegen' | null>(null);

    const zuruecksetzen = useCallback(() => {
        setWeg('wahl');
        setFormular(LEER);
        setGeteilt(false);
        setLeser([]);
        setFehler({});
        setPruefung(null);
        setArbeitet(null);
    }, []);

    function setzen<K extends keyof Formular>(feld: K, wert: Formular[K]) {
        setFormular((vorher) => ({ ...vorher, [feld]: wert }));
        // Ein Testergebnis gilt fuer die Werte, mit denen getestet wurde.
        setPruefung(null);
    }

    function nutzlast(): Record<string, unknown> {
        return {
            ...formular,
            port: Number(formular.port),
            username: formular.username || null,
            jmap_url: formular.protocol === 'jmap' && formular.jmap_url ? formular.jmap_url : null,
            smtp_host: formular.smtp_host || null,
            smtp_port: formular.smtp_host ? Number(formular.smtp_port) : null,
            smtp_encryption: formular.smtp_host ? formular.smtp_encryption : null,
            geteilt: darfTeilen && geteilt,
            leser: darfTeilen && geteilt ? leser : [],
        };
    }

    async function senden(ziel: 'pruefen' | 'anlegen') {
        setArbeitet(ziel);
        setFehler({});

        try {
            const antwort = await holen(ziel === 'pruefen' ? `${basis.replace(/\/+$/, '')}/pruefen` : basis, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(nutzlast()),
            });

            const daten = (await antwort.json().catch(() => ({}))) as {
                ok?: boolean;
                message?: string;
                errors?: Record<string, string[]>;
                account?: AngelegtesPostfach;
            };

            if (antwort.status === 422) {
                const meldungen = Object.fromEntries(Object.entries(daten.errors ?? {}).map(([feld, texte]) => [feld, texte[0] ?? '']));
                setFehler(meldungen);

                if (meldungen.verbindung) {
                    setPruefung({ ok: false, message: meldungen.verbindung });
                }

                return;
            }

            if (!antwort.ok) {
                setFehler({ allgemein: daten.message || labels.failed });

                return;
            }

            if (ziel === 'pruefen') {
                setPruefung({ ok: Boolean(daten.ok), message: daten.message ?? '' });

                return;
            }

            if (daten.account) {
                onAngelegt?.(daten.account);
            }

            setOffen(false);
            zuruecksetzen();
        } catch {
            setFehler({ allgemein: labels.failed });
        } finally {
            setArbeitet(null);
        }
    }

    function zumAnbieter(name: string) {
        navigieren(postfachVerbindenAdresse(basis, name, { name: formular.name.trim(), geteilt: darfTeilen && geteilt }));
    }

    function absenden(e: FormEvent) {
        e.preventDefault();
        void senden('anlegen');
    }

    const feld = (name: keyof Formular, beschriftung: string, art: 'text' | 'email' | 'password' | 'number' = 'text', platzhalter = '') => (
        <div className="grid gap-1.5">
            <Label htmlFor={`postfach-${name}`}>{beschriftung}</Label>
            <Input
                id={`postfach-${name}`}
                name={name}
                type={art}
                value={formular[name]}
                placeholder={platzhalter}
                autoComplete={art === 'password' ? 'new-password' : 'off'}
                onChange={(e) => setzen(name, e.target.value as Formular[typeof name])}
            />
            {fehler[name] && <p className="text-sm text-destructive">{fehler[name]}</p>}
        </div>
    );

    const verschluesselung = (name: 'encryption' | 'smtp_encryption') => (
        <div className="grid gap-1.5">
            <Label htmlFor={`postfach-${name}`}>{labels.encryption}</Label>
            <Select value={formular[name]} onValueChange={(wert) => setzen(name, wert)}>
                <SelectTrigger id={`postfach-${name}`} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {VERSCHLUESSELUNGEN.map((art) => (
                        <SelectItem key={art} value={art}>
                            {labels.encryptions[art]}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );

    const teilen = darfTeilen && (
        <div className="grid gap-2 rounded-md border p-3">
            <div className="flex items-center gap-2">
                <Checkbox id="postfach-geteilt" checked={geteilt} onCheckedChange={(wert) => setGeteilt(wert === true)} />
                <Label htmlFor="postfach-geteilt">{labels.shared}</Label>
            </div>
            <p className="text-xs text-muted-foreground">{geteilt ? labels.sharedHint : labels.personalHint}</p>
            {geteilt && weg === 'kennwort' && personen.length > 0 && (
                <div className="grid gap-1.5">
                    <Label>{labels.readers}</Label>
                    <p className="text-xs text-muted-foreground">{labels.readersHint}</p>
                    <div className="grid max-h-40 gap-1 overflow-y-auto sm:grid-cols-2">
                        {personen.map((person) => (
                            <label key={person.id} className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={leser.includes(person.id)}
                                    onCheckedChange={(wert) =>
                                        setLeser((vorher) => (wert === true ? [...vorher, person.id] : vorher.filter((id) => id !== person.id)))
                                    }
                                />
                                {person.name}
                            </label>
                        ))}
                    </div>
                    {fehler.leser && <p className="text-sm text-destructive">{fehler.leser}</p>}
                </div>
            )}
        </div>
    );

    return (
        <>
            <Button type="button" onClick={() => setOffen(true)}>
                {labels.add}
            </Button>

            <Dialog
                open={offen}
                onOpenChange={(wert) => {
                    setOffen(wert);
                    if (!wert) {
                        zuruecksetzen();
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>{labels.title}</DialogTitle>
                        <DialogDescription>{weg === 'wahl' ? labels.chooseDescription : labels.passwordDescription}</DialogDescription>
                    </DialogHeader>

                    {weg === 'wahl' ? (
                        <div className="grid gap-4">
                            {feld('name', labels.name, 'text', labels.namePlaceholder)}
                            {teilen}
                            <div className="grid gap-2">
                                {anbieter.map((name) => (
                                    <Button key={name} type="button" variant="outline" onClick={() => zumAnbieter(name)}>
                                        {labels.connectWith(labels.providers[name as keyof typeof labels.providers] ?? name)}
                                    </Button>
                                ))}
                                <Button type="button" variant="outline" onClick={() => setWeg('kennwort')}>
                                    {labels.withPassword}
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <form className="grid gap-4" onSubmit={absenden} noValidate>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {feld('name', labels.name, 'text', labels.namePlaceholder)}
                                {feld('email', labels.email, 'email', 'info@beispiel.de')}
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="postfach-protocol">{labels.protocol}</Label>
                                <Select value={formular.protocol} onValueChange={(wert) => setzen('protocol', wert as Formular['protocol'])}>
                                    <SelectTrigger id="postfach-protocol" className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="imap">IMAP</SelectItem>
                                        <SelectItem value="jmap">JMAP</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="sm:col-span-2">{feld('host', labels.host, 'text', 'imap.beispiel.de')}</div>
                                {feld('port', labels.port, 'number')}
                            </div>
                            {verschluesselung('encryption')}
                            {formular.protocol === 'jmap' && feld('jmap_url', labels.jmapUrl, 'text', labels.jmapUrlPlaceholder)}

                            <div className="grid gap-4 sm:grid-cols-2">
                                {feld('username', labels.username, 'text', labels.usernamePlaceholder)}
                                {feld('password', labels.password, 'password')}
                            </div>

                            <div className="grid gap-2">
                                <p className="text-sm font-medium">{labels.outgoing}</p>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="sm:col-span-2">{feld('smtp_host', labels.host, 'text', 'smtp.beispiel.de')}</div>
                                    {feld('smtp_port', labels.port, 'number')}
                                </div>
                                {verschluesselung('smtp_encryption')}
                            </div>

                            {teilen}

                            {pruefung && (
                                <p role="status" className={pruefung.ok ? 'text-sm text-emerald-600 dark:text-emerald-400' : 'text-sm text-destructive'}>
                                    {pruefung.ok ? labels.testOk : labels.testFailed}: {pruefung.message}
                                </p>
                            )}
                            {fehler.allgemein && <p className="text-sm text-destructive">{fehler.allgemein}</p>}

                            <DialogFooter className="gap-2 sm:justify-between">
                                <Button type="button" variant="ghost" onClick={() => setWeg('wahl')} disabled={arbeitet !== null}>
                                    {labels.back}
                                </Button>
                                <div className="flex gap-2">
                                    <Button type="button" variant="outline" onClick={() => void senden('pruefen')} disabled={arbeitet !== null}>
                                        {arbeitet === 'pruefen' ? labels.testing : labels.test}
                                    </Button>
                                    <Button type="submit" disabled={arbeitet !== null}>
                                        {arbeitet === 'anlegen' ? labels.saving : labels.save}
                                    </Button>
                                </div>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

export interface PostfachNeuVerbindenProps {
    basis: string;
    /** Die Kennung des Postfachs, das neu angemeldet wird. */
    konto: number;
    anbieter: string;
    navigieren?: (adresse: string) => void;
    labels?: PostfachAnlegenLabels;
    className?: string;
}

/**
 * Ein OAuth-Postfach neu anmelden. Der Rueckweg schreibt in GENAU diese Zeile
 * und nur, wenn die Anmeldung zur Adresse passt (`LegtKontenAn`).
 */
export function PostfachNeuVerbinden({
    basis,
    konto,
    anbieter,
    navigieren = standardNavigieren,
    labels = germanLabels.postfachAnlegen,
    className,
}: PostfachNeuVerbindenProps) {
    return (
        <Button type="button" variant="outline" size="sm" className={className} onClick={() => navigieren(postfachVerbindenAdresse(basis, anbieter, { konto }))}>
            {labels.reconnect}
        </Button>
    );
}

export default PostfachAnlegen;
