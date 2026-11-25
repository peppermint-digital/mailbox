import { X } from 'lucide-react';
import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import EmailBodyViewer, { type EmailBodyViewerLabels } from './EmailBodyViewer';
import { useMailboxPane } from './MailboxPane';
import { Button } from './ui/button';

/**
 * Eine Mail im Vollbild — oben nur der Betreff, darunter der Text.
 *
 * Gewuenscht von Bastian am 25.11.2026: „wenn ich die Mail angucke, dass
 * praktisch so gross ist wie moeglich, also oben nur der Titel und dann
 * praktisch der Rest". Die normale Leseansicht auf dem Telefon traegt ueber
 * dem Text die Navigation des Produkts, die schmale Leiste, den Betreff, die
 * Absenderzeile, die Werkzeugleiste und unten die Knopfleiste. Zum Lesen einer
 * langen Mail ist das alles im Weg.
 *
 * ## Warum ein Portal und `fixed`
 *
 * Das Vollbild soll AUCH die Navigation des Produkts verdecken — die liegt
 * ausserhalb des Pakets, und kein Kasten innerhalb des Rahmens kann sie
 * wegnehmen. `fixed` allein reichte nicht: Ein einziges `transform` an einem
 * Vorfahren macht aus dem Fenster-Bezug einen Kasten-Bezug (siehe die
 * Knopfleiste in {@see MailMessageView}). Unter `document.body` gibt es keinen
 * solchen Vorfahren.
 *
 * ## Es schliesst sich selbst, sobald nicht mehr gelesen wird
 *
 * Wechselt die Flaeche weg von der Leseansicht, ist ein Vollbild der falschen
 * Mail schlimmer als keines. Ausserhalb eines Rahmens ist die Flaeche immer
 * „Liste" (siehe `ruhend` in {@see MailboxPane}) — deshalb laesst sich das mit
 * `nurBeimLesen={false}` abschalten, fuer ein Produkt, das das Vollbild allein
 * benutzt.
 */

export interface MailVollbildLabels {
    /** Der Knopf, der das Vollbild verlaesst. */
    schliessen: string;
    /** Was oben steht, wenn die Mail keinen Betreff hat. */
    ohneBetreff: string;
    body: EmailBodyViewerLabels;
}

export interface MailVollbildProps {
    betreff?: string | null;
    bodyHtml?: string | null;
    bodyText?: string | null;
    labels: MailVollbildLabels;
    onSchliessen: () => void;
    /** Schliesst sich, sobald der Rahmen nicht mehr die Leseansicht zeigt. */
    nurBeimLesen?: boolean;
}

export function MailVollbild({ betreff, bodyHtml = null, bodyText = null, labels, onSchliessen, nurBeimLesen = true }: MailVollbildProps) {
    const { pane } = useMailboxPane();
    const weg = nurBeimLesen && pane !== 'view';

    useEffect(() => {
        if (weg) {
            onSchliessen();
        }
    }, [weg, onSchliessen]);

    /*
     * Escape schliesst — und die Seite darunter scrollt nicht mit. Ohne die
     * Sperre zieht ein Wischen am Rand auf dem iPhone die Seite HINTER dem
     * Vollbild mit, und beim Schliessen steht sie woanders.
     */
    useEffect(() => {
        if (weg) {
            return;
        }

        const taste = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onSchliessen();
            }
        };

        const vorher = document.documentElement.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        window.addEventListener('keydown', taste);

        return () => {
            document.documentElement.style.overflow = vorher;
            window.removeEventListener('keydown', taste);
        };
    }, [weg, onSchliessen]);

    if (weg || typeof document === 'undefined') {
        return null;
    }

    const titel = betreff?.trim() || labels.ohneBetreff;

    return createPortal(
        <div
            role="dialog"
            aria-modal="true"
            aria-label={titel}
            data-slot="mail-vollbild"
            className="fixed inset-0 z-[100] flex h-dvh flex-col bg-background"
        >
            {/*
                `pt-[env(safe-area-inset-top)]`: Auf dem iPhone als
                Startbildschirm-App laeuft die Seite unter die Statusleiste —
                der Knopf laege sonst unter der Uhr.
            */}
            <div className="flex shrink-0 items-center gap-1 border-b px-2 py-1 pt-[max(0.25rem,env(safe-area-inset-top))]">
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-11 w-11 shrink-0"
                    title={labels.schliessen}
                    aria-label={labels.schliessen}
                    data-slot="mail-vollbild-schliessen"
                    onClick={onSchliessen}
                >
                    <X className="h-5 w-5" />
                </Button>
                {/* Zwei Zeilen statt `truncate`: Ein abgeschnittener Betreff ist oft genau der Teil, um den es geht. */}
                <h2 className="line-clamp-2 min-w-0 flex-1 px-1 text-base leading-snug font-semibold break-words" data-slot="mail-vollbild-titel">
                    {titel}
                </h2>
            </div>

            <div className="min-h-0 flex-1 overflow-hidden pb-[env(safe-area-inset-bottom)]">
                <EmailBodyViewer labels={labels.body} bodyHtml={bodyHtml} bodyText={bodyText} blockRemoteImages />
            </div>
        </div>,
        document.body,
    );
}

export default MailVollbild;
