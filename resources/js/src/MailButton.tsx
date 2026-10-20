import { Mail } from 'lucide-react';
import { cn } from './ui/utils';

/**
 * Der Postfach-Knopf für die Kopfzeile — in jedem Produkt derselbe.
 *
 * ## Warum er in die Kopfzeile gehört
 *
 * Entschieden am 19.10.2026. Bis dahin hing der Mailbrowser in der Sidebar
 * JEDES Produkts, an vier verschiedenen Stellen mit vier Namen. Wer eine Mail
 * im CRM offen hatte und sie in der Verwaltung brauchte, suchte sie dort
 * erst — und viele wussten gar nicht, dass es den Browser auch dort gibt.
 *
 * In der Kopfzeile steht er in jedem System an derselben Stelle. Das ist die
 * ganze Idee: Dieselbe Mail, dieselbe Ansicht, egal aus welchem Fenster.
 *
 * ## Warum ein Knopf und KEINE Klappe mit Mini-Postfach
 *
 * Die naheliegende Lesart von „über die globale Leiste anzeigen" ist ein
 * Overlay, das das Postfach direkt aufzieht. Dagegen spricht eine Lehre, die
 * im Nachbarpaket schon bezahlt ist ({@link
 * https://github.com/peppermint-digital/notifications} `InboxButton`):
 *
 * > Sobald es eine Seite gibt, ist die Klappe die schlechtere Wahl: Sie zeigt
 * > einen Ausschnitt, hat eigene Aktionen und läuft gegenüber der Seite
 * > auseinander.
 *
 * Genau das träfe hier doppelt zu. Ein Postfach in einer Klappe kann nicht
 * alles, was die Seite kann — keine Ordnerspalte, kein Verfassen, keine
 * Werkzeugleiste. Was es kann, müsste ein zweites Mal gebaut und ein zweites
 * Mal gepflegt werden, und beim ersten Unterschied steht die Frage „warum
 * geht das hier nicht".
 *
 * Also: ein Symbol, ein Klick, die Seite. Die Seite ist die aus dem Paket,
 * und damit überall dieselbe.
 *
 * ## Warum hier standardmäßig KEINE Zahl steht
 *
 * Beim Posteingang steht eine Zahl am Knopf, und das ist dort billig: eine
 * Abfrage auf eine eigene Tabelle.
 *
 * Bei Mail ist es das nicht. „Wie viele ungelesene habe ich" heißt: je
 * Postfach eine Verbindung zum Mailserver, auf JEDER Seite, die diese
 * Kopfzeile trägt. Office 365 drosselt pro Postfach über alle Verbindungen —
 * dieselbe Grenze, an der die Massen-Archivierung schon einmal hing (#4159).
 * Eine Zahl, die jeden Seitenaufruf ein paar Hundert Millisekunden kostet und
 * im schlechten Fall die Drosselung auslöst, ist den Blick nicht wert.
 *
 * Ein Produkt, das die Zahl billig beantworten kann — aus einem Zwischen-
 * speicher, den sein Abruf ohnehin füllt —, übergibt `count`. Dann erscheint
 * sie. Das Paket holt sie nicht selbst: Es kennt den Zwischenspeicher nicht,
 * und wer sie selbst holt, bezahlt sie überall.
 */
export interface MailButtonProps {
    /** Wohin der Klick führt. Vorgabe: `/mailbox` */
    href?: string;

    /**
     * Die Zahl ungelesener Nachrichten — wenn das Produkt sie billig hat.
     *
     * Ohne Angabe erscheint kein Abzeichen. Siehe Klassentext: Das Paket holt
     * sie bewusst nicht selbst.
     */
    count?: number | null;

    /**
     * Navigieren. Ohne Angabe ein gewöhnlicher Link.
     *
     * Inertia-Anwendungen reichen hier ihren Router herein — sonst lädt der
     * Klick die ganze Seite neu und das Produkt verliert seinen Zustand.
     */
    onNavigate?: (href: string) => void;

    label?: string;
    className?: string;
}

const VORGABE_HREF = '/mailbox';

export function MailButton({
    href = VORGABE_HREF,
    count = null,
    onNavigate,
    label = 'Postfach',
    className,
}: MailButtonProps) {
    const zahl = typeof count === 'number' && count > 0 ? count : null;

    return (
        <a
            href={href}
            title={label}
            aria-label={zahl === null ? label : `${label}, ${zahl} ungelesen`}
            data-slot="mail-button"
            className={cn(
                'relative inline-flex h-9 w-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground',
                className,
            )}
            onClick={(ereignis) => {
                /*
                 * Nur der schlichte Linksklick wird abgefangen. Wer mit der
                 * Mitteltaste oder mit Strg klickt, will einen neuen Reiter —
                 * das abzufangen nimmt ihm eine Gewohnheit weg, die überall
                 * sonst funktioniert.
                 */
                if (!onNavigate || ereignis.defaultPrevented) {
                    return;
                }

                if (ereignis.metaKey || ereignis.ctrlKey || ereignis.shiftKey || ereignis.button !== 0) {
                    return;
                }

                ereignis.preventDefault();
                onNavigate(href);
            }}
        >
            <Mail className="h-5 w-5" />
            {zahl !== null && (
                <span
                    data-slot="mail-button-count"
                    className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] leading-none font-medium text-primary-foreground"
                >
                    {/*
                     * Ab 100 nur noch „99+": Die dreistellige Zahl sprengt das
                     * Abzeichen, und die genaue Zahl hilft ab da niemandem
                     * mehr bei der Entscheidung, ob man hinsieht.
                     */}
                    {zahl > 99 ? '99+' : zahl}
                </span>
            )}
        </a>
    );
}
