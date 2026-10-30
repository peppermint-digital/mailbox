import { ArrowLeft, BookUser, Menu, MoreVertical, PenSquare, RefreshCw, Sparkles } from 'lucide-react';
import { useMailboxPane } from './MailboxPane';
import { Button } from './ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from './ui/dropdown-menu';

/**
 * Die schmale Leiste, die auf dem Telefon an die Stelle der Kopfzeile tritt
 * (#9134, Vorbild Outlook Mobile).
 *
 * ## Was sie ersetzt, und wie viel Platz das war
 *
 * Gemeldet von Bastian am 30.10.2026: „Da liegen Buttons uebereinander … wie
 * so ein Outlook-Mobile-App … ein komplett eigenes Layout fuer Mobile." Auf
 * dem Bild einer geoeffneten Mail (CRM, iPhone 14, v0.184.0) standen ueber dem
 * Mailtext DREI Baender uebereinander, bevor der Betreff ueberhaupt anfing:
 *
 * | Band | Hoehe |
 * |---|---|
 * | Kopfzeile: „E-Mails" + Kontoauswahl + drei Knoepfe | ~130 px |
 * | „Zurueck zur Liste" als eigene Zeile | ~45 px |
 * | Werkzeugleiste des Produkts, aufgeklappt | ~180 px |
 *
 * Auf 844 px Bildschirmhoehe fing der Mailtext bei rund 750 px an. Diese
 * Leiste ist EIN Band von rund 44 px und uebernimmt die Aufgaben aller drei.
 *
 * ## Warum sie eine eigene Komponente ist und keine Fallunterscheidung
 *
 * {@see MailboxHeader} bleibt unveraendert, und zwar absichtlich: Sie wird
 * auch direkt benutzt, und eine Kopfzeile, die je nach Breite etwas anderes
 * ist, waere an beiden Enden schwerer zu lesen als zwei Komponenten, von denen
 * jede eine Sache tut. Welche man sieht, entscheidet die Klasse (`md:hidden`
 * hier, `max-md:hidden` dort) — nicht JavaScript.
 *
 * Das ist dieselbe Entscheidung wie in {@see MailboxPane}: Ein per
 * `matchMedia` gemessener Zustand waere beim ersten Zeichnen noch falsch und
 * beim Drehen des Geraets ein zweiter Ort, der nachziehen muss. Die
 * Kontoauswahl steht deshalb ZWEIMAL im Baum — hier in der Schublade, dort in
 * der Kopfzeile. Sie liest denselben Zustand, also kostet das kein zweites
 * Laden und es gibt keinen zweiten Stand.
 *
 * ## Was sie zeigt, haengt an der Flaeche, nicht an der Breite
 *
 * - **Liste:** Menue-Knopf (holt die Schublade mit Konten und Ordnern), der
 *   ORDNERNAME als Titel, Assistent, Ueberlauf.
 * - **Lesen:** Zurueck-Pfeil und der ABSENDER. Kein Menue — wer liest, will
 *   zurueck, nicht in einen anderen Ordner.
 * - **Schublade:** Zurueck-Pfeil, sonst nichts; die Schublade bringt ihren
 *   Inhalt selbst mit.
 *
 * Der Ordnername statt „E-Mails": Das ist Outlook-Mobile-Verhalten und
 * ausdruecklich so gewollt. Der Seitentitel steht ohnehin schon in der
 * Navigation des Produkts daneben — ihn hier zu wiederholen kostete eine Zeile
 * und sagte nichts.
 */

export interface MailboxMobilLeisteLabels {
    /** Der Knopf, der Konten und Ordner hereinholt. */
    menue: string;
    /** Zurueck von der Nachricht oder der Schublade zur Liste. */
    zurueck: string;
    /** Der Griff zum Postfach-Assistenten. */
    assistent: string;
    /** Das Ueberlaufmenue mit dem, was selten gebraucht wird. */
    mehr: string;
    adressbuch: string;
    aktualisieren: string;
}

export const mailboxMobilLeisteLabels: MailboxMobilLeisteLabels = {
    menue: 'Konten und Ordner',
    zurueck: 'Zurück zur Liste',
    assistent: 'Postfach-Assistent',
    mehr: 'Weitere Aktionen',
    adressbuch: 'Adressbuch',
    aktualisieren: 'Aktualisieren',
};

export interface MailboxMobilLeisteProps {
    /** Der Ordner, in dem man steht — der Titel auf der Liste. */
    ordnerName: string;
    /** Wer geschrieben hat — der Titel beim Lesen. Fehlt er, bleibt es leer. */
    absender?: string;
    /**
     * Gibt es ueberhaupt Ordner? Nur dann steht der Menue-Knopf da.
     *
     * Was er tut, macht die Leiste selbst (`showPane('folders')`) — aus
     * demselben Grund, aus dem die Liste und die Ordner selbst umschalten:
     * Als Rueckruf muesste jedes Produkt ihn verdrahten, und mobil bliebe so
     * lange kaputt, wie eines es nicht getan hat.
     */
    hatOrdner?: boolean;
    onAssistent?: () => void;
    onAdressbuch?: () => void;
    onAktualisieren?: () => void;
    aktualisiert?: boolean;
    labels?: MailboxMobilLeisteLabels;
}

export function MailboxMobilLeiste({
    ordnerName,
    absender,
    hatOrdner = true,
    onAssistent,
    onAdressbuch,
    onAktualisieren,
    aktualisiert = false,
    labels = mailboxMobilLeisteLabels,
}: MailboxMobilLeisteProps) {
    const { pane, showPane } = useMailboxPane();

    const liest = pane === 'view';
    const inSchublade = pane === 'folders';
    const titel = liest ? (absender ?? '') : ordnerName;

    return (
        <div className="flex items-center gap-1 border-b px-2 py-1.5 md:hidden" data-slot="mailbox-mobil-leiste">
            {liest || inSchublade ? (
                <Button variant="ghost" size="icon" className="h-11 w-11" title={labels.zurueck} aria-label={labels.zurueck} onClick={() => showPane('list')}>
                    <ArrowLeft className="h-5 w-5" />
                </Button>
            ) : (
                hatOrdner && (
                    <Button
                        variant="ghost"
                        size="icon"
                        className="h-11 w-11"
                        title={labels.menue}
                        aria-label={labels.menue}
                        data-slot="mailbox-mobil-menue"
                        onClick={() => showPane('folders')}
                    >
                        <Menu className="h-5 w-5" />
                    </Button>
                )
            )}

            {/*
                `min-w-0` und `truncate`: Ein Absender wie
                „newsletter@service.tiktok.com" ist ein Wort ohne Trennstelle.
                Ohne beides schiebt er die Knoepfe rechts aus dem Bild — genau
                die Klasse Fehler, die diese Leiste beseitigen soll.
            */}
            <span className="min-w-0 flex-1 truncate px-1 text-base font-semibold" data-slot="mailbox-mobil-titel" title={titel}>
                {titel}
            </span>

            {/*
                Der Assistent steht hier und NICHT als schwebender Griff unten
                rechts. Dort sass er bis zum 30.10.2026 — zusammen mit dem
                Verfassen-Knopf, der ebenfalls unten rechts schwebt. Zwei
                schwebende Knoepfe an derselben Ecke sind woertlich Bastians
                „Buttons uebereinander".
            */}
            {onAssistent && !inSchublade && (
                <Button variant="ghost" size="icon" className="h-11 w-11" title={labels.assistent} aria-label={labels.assistent} onClick={onAssistent}>
                    <Sparkles className="h-5 w-5" />
                </Button>
            )}

            {(onAdressbuch || onAktualisieren) && !inSchublade && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-11 w-11" title={labels.mehr} aria-label={labels.mehr}>
                            <MoreVertical className="h-5 w-5" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        {onAktualisieren && (
                            <DropdownMenuItem onSelect={onAktualisieren} disabled={aktualisiert}>
                                <RefreshCw className={`mr-2 h-4 w-4 ${aktualisiert ? 'animate-spin' : ''}`} />
                                {labels.aktualisieren}
                            </DropdownMenuItem>
                        )}
                        {onAdressbuch && (
                            <DropdownMenuItem onSelect={onAdressbuch}>
                                <BookUser className="mr-2 h-4 w-4" />
                                {labels.adressbuch}
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );
}

/**
 * Der schwebende Verfassen-Knopf, nur am Telefon (#9134).
 *
 * In der Kopfzeile steht „Neue E-Mail" als breiter Knopf mit Text — und genau
 * der hat die Kopfzeile auf 390 px in eine zweite Zeile gedrueckt. Hier ist er
 * ein Kreis in der unteren rechten Ecke, wie in jeder Mail-App auf einem
 * Telefon.
 *
 * Er ist eine eigene Komponente und kein Stueck Markup in der Flaeche, damit
 * die Breitenmessung ihn mitmessen kann: Dass er mit NICHTS anderem kollidiert,
 * ist die Zusicherung, um die es in #9134 geht. Markup, das nur in einer
 * Produktdatei steht, kann ein Pakettest nicht pruefen.
 *
 * ## Nur auf der Liste — und das hat die Messung gefunden
 *
 * `bottom-5 right-5` ist dieselbe Ecke, in der bis zum 30.10.2026 der Griff
 * zum Assistenten sass. Der ist deshalb in die Leiste oben gewandert.
 *
 * Beim Lesen sitzt dort seit derselben Aenderung die Knopfleiste der Mail, und
 * zwar ueber die ganze Breite. Ein schwebender Knopf daneben waere genau
 * wieder das Gemeldete: ein Knopf auf einem Knopf. Beim Lesen verfasst man
 * ohnehin nicht neu, sondern antwortet — und dafuer steht ein Knopf in der
 * Leiste. Also: nur auf der Liste.
 *
 * `absolute` und nicht `fixed`, aus demselben Grund wie bei der Knopfleiste:
 * Der Bezug soll der Rahmen sein und nicht das Fenster. Deshalb gehoert er in
 * den `schwebend`-Platz von {@see MailboxBrowser} — dort steht er auch im
 * Kontext der Flaechen und kann sich selbst wegnehmen.
 */
export function MailboxVerfassenKnopf({ label, onClick }: { label: string; onClick: () => void }) {
    const { pane } = useMailboxPane();

    if (pane !== 'list') return null;

    return (
        <button
            type="button"
            data-slot="mailbox-verfassen-mobil"
            aria-label={label}
            title={label}
            className="absolute right-5 bottom-5 z-30 flex h-14 w-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg md:hidden"
            onClick={onClick}
        >
            <PenSquare className="h-6 w-6" />
        </button>
    );
}

export default MailboxMobilLeiste;
