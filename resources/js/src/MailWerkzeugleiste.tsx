import {
    ArrowUpRight,
    CircleAlert,
    FileText,
    ListChecks,
    Loader2,
    Receipt,
    Ticket,
    TriangleAlert,
    User,
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
    wrench: Wrench,
};

export interface MailWerkzeugleisteProps {
    werkzeuge: MailWerkzeug[];
    unerreichbar: string[];
    gescheitert: string[];
    laedt: boolean;
    leerAberVollstaendig: boolean;
    erreicht: boolean | null;
    ausloesen: (werkzeug: MailWerkzeug) => Promise<{ ok: boolean; message: string }>;
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
    labels,
    className,
}: MailWerkzeugleisteProps) {
    const texte = { ...WERKZEUGLEISTE_VORGABEN, ...labels };
    const [laufend, setLaufend] = useState<string | null>(null);

    const sichtbar = verbergen ? werkzeuge.filter((w) => !verbergen(w)) : werkzeuge;

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

        setLaufend(werkzeug.key + (werkzeug.system ?? ''));

        try {
            const ergebnis = await ausloesen(werkzeug);
            onMeldung?.(ergebnis.message, ergebnis.ok);
        } finally {
            setLaufend(null);
        }
    }

    return (
        <div data-slot="mail-werkzeugleiste" className={cn('flex flex-col gap-1.5 border-b px-3 py-2', className)}>
            <div className="flex flex-wrap items-center gap-1.5">
                <span className="mr-1 text-xs font-medium text-muted-foreground">{texte.titel}</span>

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

                    return (
                        <Button
                            key={kennung}
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={beschaeftigt}
                            onClick={() => void klick(werkzeug)}
                            className="h-7 gap-1 px-2 text-xs"
                        >
                            {beschaeftigt ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Symbol className="h-3.5 w-3.5" />}
                            {beschaeftigt ? texte.laeuft : werkzeug.label}
                            {werkzeug.system_label && <Badge variant="outline">{werkzeug.system_label}</Badge>}
                        </Button>
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

            {gescheitert.length > 0 && (
                <p className="flex items-start gap-1 text-xs text-destructive">
                    <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {texte.gescheitert(gescheitert)}
                </p>
            )}
        </div>
    );
}
