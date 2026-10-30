import {
    ArrowUpRight,
    ChevronDown,
    CircleAlert,
    FileText,
    ListChecks,
    Loader2,
    Receipt,
    Ticket,
    TriangleAlert,
    User,
    UserCheck,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import type { MailWerkzeug } from './useMailWerkzeuge';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { cn } from './ui/utils';

/**
 * Eine Werkzeugleiste für ALLE Systeme, an der geöffneten Mail (#8313/#8316).
 *
 * ## Was sie ersetzt
 *
 * Bis zum 20.10.2026 hatte jedes Produkt seine eigene Leiste an derselben
 * Stelle: das CRM eine `MailboxContactBar`, die Verwaltung eine
 * `MailboxTicketBar` und eine `MailboxAttachmentBar`, der Manager seinen
 * Aufgaben-Knopf. Jede kannte genau ein Verb — ihr eigenes.
 *
 * Die Folge war nicht Vielfalt, sondern Blindheit: Wer eine Mail im CRM offen
 * hatte, konnte dort nicht sehen, dass sie im Manager längst an „Angebot
 * Meier überarbeiten" hängt. Er hängte sie ein zweites Mal an eine andere
 * Aufgabe, und niemand bekam eine Meldung.
 *
 * Diese Leiste zeigt alle Verben, die dieser Person an dieser Mail offenstehen
 * — eigene und die der Nachbarsysteme, in einer Reihe, mit dem Namen des
 * Systems am Verb, wenn es ein fremdes ist.
 *
 * ## Die eine Regel, von der alles andere hängt
 *
 * **Eine leere Leiste und eine ausgefallene Leiste dürfen nicht gleich
 * aussehen.**
 *
 * „Keine Werkzeuge" heißt: hier ist nichts zu tun. Antwortet die Verwaltung
 * gerade nicht, ist die Wahrheit „das Ticket-System antwortet nicht" — und
 * jemand, der stattdessen ein leeres Nichts sieht, legt sein Ticket von Hand
 * ein zweites Mal an.
 *
 * Dieses Paket hat dieselbe Regel zweimal bezahlt: bei der Zuständigkeit
 * („leere Antwort ≠ leerer Bestand", 503 statt stiller Leere) und bei der
 * Massenaktion (ein Teilerfolg, der als „nicht erreichbar" ankam). Hier steht
 * sie zum dritten Mal, und deshalb ist sie der Grund für die drei getrennten
 * Zeilen unten: Werkzeuge, `unerreichbar`, `gescheitert`.
 *
 * ## Warum ein Verweis-Verb ein Link ist und kein Knopf
 *
 * Ein Kontakt wird aus zwölftausend gewählt, eine Aufgabe braucht Projekt und
 * Bearbeiter. Diesen Auswähler kann ein fremdes System nicht zeichnen, und zu
 * raten legt Arbeit in die falsche Schublade. Also: Wer `url` trägt, führt
 * hin. Das sieht man am Pfeil, und es ist dieselbe Entscheidung, die der
 * Server in `fuehrtHin()` trifft — hier nur sichtbar gemacht.
 */

/** Die Wörter. Vorgaben auf Deutsch; `germanLabels.werkzeuge` reicht sie herein. */
export interface MailWerkzeugleisteLabels {
    titel: string;
    leer: string;
    laedt: string;
    /** `(systeme) => …` — welche Systeme nicht geantwortet haben. */
    unerreichbar: (systeme: string[]) => string;
    /** `(verben) => …` — welche Verben geantwortet und gescheitert sind. */
    gescheitert: (verben: string[]) => string;
    nichtGefragt: string;
    laeuft: string;
    /**
     * `(verben) => …` — Verben, deren Eingabe diese Ansicht nicht zeichnen kann.
     *
     * Kein toter Knopf und kein stilles Weglassen: Das eine scheitert
     * zuverlässig, das andere sieht aus wie „gibt es nicht".
     */
    nichtZeichenbar: (verben: string[]) => string;
    /** Was in einer leeren Auswahl steht, solange niemand gewählt hat. */
    bitteWaehlen: string;
}

export const WERKZEUGLEISTE_VORGABEN: MailWerkzeugleisteLabels = {
    titel: 'Werkzeuge',
    leer: 'Für diese Nachricht ist nichts zu tun.',
    laedt: 'Werkzeuge werden geladen …',
    unerreichbar: (systeme) =>
        systeme.length === 1
            ? `${systeme[0]} antwortet nicht — was dort zu dieser Mail gehört, fehlt in dieser Liste.`
            : `${systeme.join(', ')} antworten nicht — was dort zu dieser Mail gehört, fehlt in dieser Liste.`,
    gescheitert: (verben) =>
        verben.length === 1
            ? `„${verben[0]}" konnte nicht geladen werden.`
            : `${verben.length} Werkzeuge konnten nicht geladen werden: ${verben.join(', ')}.`,
    nichtGefragt: 'Die Werkzeuge konnten nicht abgefragt werden.',
    laeuft: 'Wird ausgeführt …',
    nichtZeichenbar: (verben) =>
        verben.length === 1
            ? `„${verben[0]}" braucht eine Angabe, die diese Ansicht nicht erfassen kann.`
            : `${verben.length} Werkzeuge brauchen Angaben, die diese Ansicht nicht erfassen kann: ${verben.join(', ')}.`,
    bitteWaehlen: 'Bitte wählen …',
};

/**
 * Symbole für die Verben, die es heute gibt.
 *
 * Als Zuordnung und nicht als freie Angabe aus dem Server: Ein Produkt
 * schickt `'ticket'`, und welches Bildchen das ist, entscheidet die
 * Oberfläche. Ein unbekannter Name bekommt den Schraubenschlüssel — ein
 * fehlendes Symbol darf kein leeres Loch sein.
 */
const SYMBOLE: Record<string, typeof Wrench> = {
    ticket: Ticket,
    receipt: Receipt,
    'file-text': FileText,
    'list-checks': ListChecks,
    user: User,
    'user-check': UserCheck,
    wrench: Wrench,
};

export interface MailWerkzeugleisteProps {
    werkzeuge: MailWerkzeug[];
    unerreichbar: string[];
    gescheitert: string[];
    laedt: boolean;
    leerAberVollstaendig: boolean;
    erreicht: boolean | null;
    ausloesen: (werkzeug: MailWerkzeug, eingabe?: Record<string, unknown>) => Promise<{ ok: boolean; message: string }>;
    /** Wird nach einem ausgelösten Verb mit dem Satz des Systems gerufen. */
    onMeldung?: (meldung: string, gelungen: boolean) => void;
    /** Inertia-Anwendungen reichen hier ihren Router herein. */
    onNavigate?: (href: string) => void;
    /**
     * Verben, die dieses Produkt schon selbst zeichnet.
     *
     * Ein Produkt MUSS sein Verb im Verzeichnis anmelden, damit die anderen
     * Systeme es sehen — aber es kann es selbst besser koennen. Das CRM hat
     * eine eigene Kontaktleiste mit Suche, Verknuepfen und Loesen; das Verb
     * `kontakt` fuehrt dagegen nur ins CRM. Beides nebeneinander zu zeigen
     * waere dieselbe Sache zweimal, einmal schlechter.
     *
     * Ein Praedikat und keine Liste von Schluesseln: Die Entscheidung haengt
     * oft am System („alles Eigene zeichne ich selbst"), nicht am einzelnen
     * Verb.
     *
     * Ausblenden heisst NICHT abmelden. Die anderen Systeme sehen das Verb
     * weiter — hier verschwindet nur der doppelte Knopf.
     */
    verbergen?: (werkzeug: MailWerkzeug) => boolean;
    /**
     * Verben, die dieses Produkt selbst BEDIENT — Schlüssel → Handler.
     *
     * Der Unterschied zu `verbergen` ist der Punkt: Verbergen heisst „ich
     * zeichne das woanders", Beanspruchen heisst „der Knopf bleibt hier, aber
     * er öffnet meinen Dialog".
     *
     * Der Fall, für den es gebaut ist (#8434): Der Manager hat für „Aufgabe
     * anhängen" einen Dialog mit Suche und Anhangwahl — den kann ein fremdes
     * System nicht zeichnen. Bisher blendete er das Verb deshalb aus und setzte
     * einen EIGENEN Knopf neben den Betreff. Damit stand dieselbe Sache zweimal
     * im Bild, unter zwei Namen („Aufgabe anhängen" in der Leiste der Nachbarn,
     * „Aufgabe zuordnen" am Titel), und die Werkzeugleiste war nicht mehr der
     * Ort, an dem Werkzeuge stehen.
     *
     * Der Nutzer hat genau das gemeldet: „Das wäre ja auch wieder eine
     * Werkzeugaktion und keine Aktion im E-Mail-Feld."
     *
     * Beansprucht wird nur das Auslösen. Das Verb bleibt angemeldet, trägt
     * seinen Namen, seine Reihenfolge und sein Symbol aus dem Verzeichnis —
     * damit es im eigenen System genauso heisst wie bei den Nachbarn.
     */
    eigene?: Record<string, () => void>;
    labels?: Partial<MailWerkzeugleisteLabels>;
    className?: string;
}

export function MailWerkzeugleiste({
    werkzeuge,
    unerreichbar,
    gescheitert,
    laedt,
    leerAberVollstaendig,
    erreicht,
    ausloesen,
    onMeldung,
    onNavigate,
    verbergen,
    eigene,
    labels,
    className,
}: MailWerkzeugleisteProps) {
    const texte = { ...WERKZEUGLEISTE_VORGABEN, ...labels };
    const [laufend, setLaufend] = useState<string | null>(null);

    /*
     * Auf dem Telefon ist diese Leiste zugeklappt (#9134).
     *
     * Gemessen am 30.10.2026 an Bastians Bild (CRM, iPhone 14): aufgeklappt
     * rund 180 px von 844 — und das ueber der Mail, die man lesen wollte. Die
     * Verben sind richtig und sollen bleiben; sie sind nur nicht das, weswegen
     * jemand eine Mail oeffnet.
     *
     * Der Zustand steht hier und nicht in einer Klasse, weil es ein Zustand
     * IST: Wer aufklappt, will es offen sehen. Dass es den Zustand am
     * Schreibtisch nicht gibt, erledigt `max-md:hidden` — dort ist die Leiste
     * immer offen, egal was hier steht. Kein `matchMedia`, kein zweiter Stand,
     * der beim ersten Zeichnen noch nicht stimmt.
     */
    const [offen, setOffen] = useState(false);

    /**
     * Was in den Feldern steht, je Werkzeug.
     *
     * Nach Kennung (Schlüssel + System) und nicht nach Schlüssel allein:
     * „Aufgabe anhängen" gibt es in zwei Systemen, und das sind zwei Verben.
     */
    const [eingaben, setEingaben] = useState<Record<string, Record<string, string>>>({});

    const sichtbar = verbergen ? werkzeuge.filter((w) => !verbergen(w)) : werkzeuge;

    /*
     * Verben, deren Pflichteingabe diese Ansicht nicht erfassen kann.
     *
     * Sie werden NICHT als Knopf angeboten — ein Verb, das seine Angabe nicht
     * einsammeln kann, scheitert drüben zuverlässig. Aber sie werden auch
     * nicht verschwiegen: Ein stilles Weglassen sieht aus wie „gibt es nicht",
     * und dann sucht jemand den Fehler bei sich.
     */
    const zeichenbar = (werkzeug: MailWerkzeug): boolean =>
        (werkzeug.fields ?? []).every((feld) => feld.type === 'select');

    /**
     * Die WIRKSAMEN Werte eines Verbs: was der Mensch gewählt hat, sonst die
     * Vorbelegung des Servers.
     *
     * Eine Funktion und keine zwei Rechnungen — das Zeichnen und der Klick
     * müssen dasselbe ergeben. Der erste Entwurf hatte zwei: Die Leiste
     * ZEIGTE die vorbelegte Person und SCHICKTE ein leeres Objekt, weil die
     * Vorbelegung nur im Zeichnen stand. Der Server hätte korrekt „es wurde
     * niemand ausgewählt" geantwortet, während im Feld jemand stand.
     *
     * `??` je Feld: Wer eines von zwei Feldern angefasst hat, soll das andere
     * weiter vorbelegt sehen — und mitschicken.
     */
    function werteFuer(werkzeug: MailWerkzeug): Record<string, string> {
        const kennung = werkzeug.key + (werkzeug.system ?? '');
        const werte: Record<string, string> = {};

        for (const feld of werkzeug.fields ?? []) {
            werte[feld.name] = eingaben[kennung]?.[feld.name] ?? feld.value ?? '';
        }

        return werte;
    }

    const nichtZeichenbar = sichtbar.filter((w) => !w.url && !w.state && !zeichenbar(w));

    /*
     * `leerAberVollstaendig` bleibt die Auskunft des Hakens und wird NICHT
     * aus `sichtbar.length` neu berechnet. Ein Produkt, das sein eigenes Verb
     * ausblendet, hat dadurch nicht „nichts zu tun" — es zeigt es nur
     * woanders. Der Satz „fuer diese Nachricht ist nichts zu tun" gilt nur,
     * wenn der SERVER nichts hatte.
     */
    const darfLeerSagen = leerAberVollstaendig && werkzeuge.length === 0;

    // Noch nicht gefragt: nichts zeigen. Eine Leiste, die beim ersten Rendern
    // „nichts zu tun" behauptet, hat nichts versucht.
    if (erreicht === null && !laedt) {
        return null;
    }

    async function klick(werkzeug: MailWerkzeug) {
        if (werkzeug.confirm && !globalThis.confirm?.(werkzeug.confirm)) {
            return;
        }

        /*
         * Beansprucht dieses Produkt das Verb, übernimmt es hier — ohne
         * Serveraufruf und ohne Laufanzeige. Was danach passiert, weiss nur
         * das Produkt: Der Manager öffnet einen Dialog, und ob der zu etwas
         * führt, entscheidet sich dort.
         *
         * VOR der Laufanzeige, nicht danach: Ein Knopf, der sich beim Öffnen
         * eines Dialogs als „wird ausgeführt" markiert und dann nie fertig
         * wird, sieht aus wie ein Hänger.
         */
        const eigenerWeg = eigene?.[werkzeug.key];

        if (eigenerWeg) {
            eigenerWeg();

            return;
        }

        const kennung = werkzeug.key + (werkzeug.system ?? '');

        setLaufend(kennung);

        try {
            const ergebnis = await ausloesen(werkzeug, werteFuer(werkzeug));
            onMeldung?.(ergebnis.message, ergebnis.ok);

            if (ergebnis.ok) {
                // Nach einem gelungenen Verb die Auswahl zurücksetzen. Eine
                // stehengebliebene Auswahl liest sich wie „das ist der
                // aktuelle Stand" — der steht aber im Zustand, den der Server
                // neu schickt.
                setEingaben((vorher) => {
                    const naechste = { ...vorher };
                    delete naechste[kennung];

                    return naechste;
                });
            }
        } finally {
            setLaufend(null);
        }
    }

    return (
        <div data-slot="mail-werkzeugleiste" className={cn('flex flex-col gap-1.5 border-b px-3 py-2', className)}>
            {/*
                Der Aufklapper gibt es nur am Telefon. Ein Knopf und keine
                Ueberschrift: Er tut etwas, also muss man ihn mit der Tastatur
                erreichen und er braucht einen Zustand, den eine Vorlesehilfe
                ansagen kann.
            */}
            <button
                type="button"
                data-slot="mail-werkzeugleiste-aufklapper"
                aria-expanded={offen}
                className="-mx-1 flex min-h-10 items-center gap-1.5 rounded px-1 text-left text-xs font-medium text-muted-foreground md:hidden"
                onClick={() => setOffen((war) => !war)}
            >
                <ChevronDown className={cn('h-4 w-4 shrink-0 transition-transform', offen && 'rotate-180')} />
                {texte.titel}
            </button>

            <div className={cn('flex flex-col gap-1.5', !offen && 'max-md:hidden')} data-slot="mail-werkzeugleiste-inhalt">
            <div className="flex flex-wrap items-center gap-1.5">
                <span className="mr-1 text-xs font-medium text-muted-foreground max-md:hidden">{texte.titel}</span>

                {laedt && (
                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                        <Loader2 className="h-3 w-3 animate-spin" />
                        {texte.laedt}
                    </span>
                )}

                {sichtbar.map((werkzeug) => {
                    const Symbol = SYMBOLE[werkzeug.icon ?? ''] ?? Wrench;
                    const kennung = werkzeug.key + (werkzeug.system ?? '');
                    const beschaeftigt = laufend === kennung;

                    /*
                     * Der ZUSTAND schlägt das Verb.
                     *
                     * Gibt es das Ticket schon, ist „Ticket anlegen" die
                     * falsche Einladung — dann gehört dorthin ein Weg zu dem,
                     * was existiert. Das ist der eigentliche Gewinn der
                     * gemeinsamen Leiste: Man sieht es, ohne das andere
                     * System zu öffnen.
                     */
                    if (werkzeug.state) {
                        return werkzeug.state.url ? (
                            <a
                                key={kennung}
                                href={werkzeug.state.url}
                                onClick={(e) => {
                                    if (!onNavigate || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) {
                                        return;
                                    }
                                    e.preventDefault();
                                    onNavigate(werkzeug.state!.url!);
                                }}
                                className="inline-flex items-center gap-1 rounded-md border border-primary/30 bg-primary/5 px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-primary/10"
                            >
                                <Symbol className="h-3.5 w-3.5" />
                                {werkzeug.state.label}
                                {werkzeug.system_label && <Badge variant="outline">{werkzeug.system_label}</Badge>}
                            </a>
                        ) : (
                            <span
                                key={kennung}
                                className="inline-flex items-center gap-1 rounded-md border bg-muted px-2 py-1 text-xs text-muted-foreground"
                            >
                                <Symbol className="h-3.5 w-3.5" />
                                {werkzeug.state.label}
                            </span>
                        );
                    }

                    // Führt hin: ein Link, kein Knopf. Siehe Klassentext.
                    if (werkzeug.url) {
                        return (
                            <a
                                key={kennung}
                                href={werkzeug.url}
                                onClick={(e) => {
                                    if (!onNavigate || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) {
                                        return;
                                    }
                                    e.preventDefault();
                                    onNavigate(werkzeug.url!);
                                }}
                                className="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs transition-colors hover:bg-accent hover:text-accent-foreground"
                            >
                                <Symbol className="h-3.5 w-3.5" />
                                {werkzeug.label}
                                <ArrowUpRight className="h-3 w-3 opacity-60" />
                                {werkzeug.system_label && <Badge variant="outline">{werkzeug.system_label}</Badge>}
                            </a>
                        );
                    }

                    // Eine Angabe, die diese Ansicht nicht erfassen kann:
                    // kein Knopf. Gesagt wird es unten in einer eigenen
                    // Zeile, damit es nicht wie „gibt es nicht" aussieht.
                    if (!zeichenbar(werkzeug)) {
                        return null;
                    }

                    const felder = werkzeug.fields ?? [];
                    const werte = werteFuer(werkzeug);

                    /*
                     * Fehlt eine Pflichtangabe, ist der Knopf aus — nicht
                     * verborgen. Verborgen hiesse „das Verb gibt es hier
                     * nicht"; aus heisst „es fehlt noch etwas", und daneben
                     * steht, was.
                     */
                    const fehltPflicht = felder.some(
                        (feld) => feld.required && (werte[feld.name] ?? '') === '',
                    );

                    return (
                        <span key={kennung} className="flex flex-wrap items-center gap-1">
                            {felder.map((feld) => (
                                <select
                                    key={feld.name}
                                    aria-label={feld.label}
                                    value={werte[feld.name]}
                                    disabled={beschaeftigt}
                                    onChange={(e) =>
                                        setEingaben((vorher) => ({
                                            ...vorher,
                                            [kennung]: { ...(vorher[kennung] ?? {}), [feld.name]: e.target.value },
                                        }))
                                    }
                                    className="h-7 max-w-[12rem] rounded-md border bg-background px-1.5 text-xs"
                                >
                                    <option value="">{texte.bitteWaehlen}</option>
                                    {(feld.options ?? []).map((wahl) => (
                                        <option key={wahl.value} value={wahl.value}>
                                            {wahl.label}
                                        </option>
                                    ))}
                                </select>
                            ))}

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={beschaeftigt || fehltPflicht}
                                onClick={() => void klick(werkzeug)}
                                className="h-7 gap-1 px-2 text-xs"
                            >
                                {beschaeftigt ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Symbol className="h-3.5 w-3.5" />}
                                {beschaeftigt ? texte.laeuft : werkzeug.label}
                                {werkzeug.system_label && <Badge variant="outline">{werkzeug.system_label}</Badge>}
                            </Button>
                        </span>
                    );
                })}

                {/*
                 * „Nichts zu tun" NUR, wenn auch nichts fehlt. Die Auskunft
                 * kommt aus `leerAberVollstaendig`, nicht aus `length === 0`
                 * — der Unterschied ist der ganze Grund für diese Leiste.
                 */}
                {!laedt && darfLeerSagen && <span className="text-xs text-muted-foreground">{texte.leer}</span>}

                {!laedt && erreicht === false && (
                    <span className="flex items-center gap-1 text-xs text-destructive">
                        <CircleAlert className="h-3.5 w-3.5" />
                        {texte.nichtGefragt}
                    </span>
                )}
            </div>

            {/*
             * Zwei getrennte Zeilen, weil es zwei Lagen sind:
             *
             *   unerreichbar — das System hat nicht geantwortet (abwarten)
             *   gescheitert  — es hat geantwortet und konnte nicht (nachsehen)
             *
             * Beides als eine Warnung zu zeigen hiesse, jemanden abwarten zu
             * lassen, wo etwas kaputt ist.
             */}
            {unerreichbar.length > 0 && (
                <p className="flex items-start gap-1 text-xs text-amber-700 dark:text-amber-500">
                    <TriangleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {texte.unerreichbar(unerreichbar)}
                </p>
            )}

            {/*
             * Die dritte Lage, und sie ist von den beiden anderen zu
             * unterscheiden: Das System hat geantwortet, das Verb ist in
             * Ordnung — nur DIESE Ansicht kann seine Angabe nicht erfassen.
             * Abwarten hilft nicht, nachsehen auch nicht; es ist eine fehlende
             * Oberfläche, und das gehört gesagt statt verschwiegen.
             */}
            {nichtZeichenbar.length > 0 && (
                <p className="flex items-start gap-1 text-xs text-muted-foreground">
                    <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {texte.nichtZeichenbar(nichtZeichenbar.map((w) => w.label))}
                </p>
            )}

            {gescheitert.length > 0 && (
                <p className="flex items-start gap-1 text-xs text-destructive">
                    <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {texte.gescheitert(gescheitert)}
                </p>
            )}
            </div>
        </div>
    );
}
