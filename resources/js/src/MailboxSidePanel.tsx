import { useCallback, useEffect, useState, type ReactNode } from 'react';

/**
 * The column beside the reading pane — an assistant, a contact, a task rail.
 *
 * ## Why the package owns this and not just the content
 *
 * The frame is the same everywhere: a heading, a close button, a way back in,
 * and the memory of whether it was open. Every product writes that identically,
 * and two details in it are easy to get wrong:
 *
 * - The way back in. A panel that closes without leaving a handle is gone for
 *   good as far as the user is concerned — they do not know it can come back.
 * - The reading of the stored flag. `localStorage` throws outright in a private
 *   window in some browsers; an unguarded read takes the whole page down, and
 *   it takes a browser nobody tests in to find out.
 *
 * ## The stored value is per browser, not per user
 *
 * Whether the assistant is open is a convenience of this one screen. It is
 * deliberately not carried to the server: it would be one more thing to migrate
 * and one more write on every click, for a preference that is meaningless on
 * another device anyway.
 */

/** Remember whether a side panel is open — across reloads, per browser. */
export function useMailboxSidePanel(
    key: string,
    standard = true,
): { sichtbar: boolean; gewaehlt: boolean; zeigen: () => void; verbergen: () => void } {
    const [sichtbar, setSichtbar] = useState(standard);

    /**
     * Ist dieser Zustand eine ENTSCHEIDUNG oder nur die Vorgabe?
     *
     * Der Unterschied klang lange nach Haarspalterei und hat am 29.10.2026 die
     * Handy-Ansicht gekostet: Seit v0.154.0 deckt das Panel unterhalb von `md`
     * die ganze Flaeche ab — mit der Begruendung, es sei ja gerade absichtlich
     * geoeffnet worden und bringe seinen eigenen Schliessen-Knopf mit.
     *
     * Nur ist es das beim ERSTEN Aufruf nicht. `standard` ist `true`, also lag
     * der Assistent auf dem Telefon ab Werk ueber der Mailliste. Man sah den
     * Chat statt seiner Post.
     *
     * Die Unterscheidung ist gratis: Eine Wahl landet in `localStorage`. Steht
     * dort nichts, ist der offene Zustand die Vorgabe — und dann darf er auf
     * einem schmalen Schirm nichts verdecken.
     *
     * Gefunden hat das nicht die Messung, sondern ein Screenshot: Die
     * DOM-Werte sagten „Liste sichtbar, 386 px, kein Ueberlauf", und jede Zahl
     * davon stimmte. Sie war nur zugedeckt.
     *
     * ## Und dieselbe Frage ein zweites Mal, am 30.10.2026
     *
     * Die Unterscheidung war richtig und die Grenze an der falschen Stelle:
     * Ein Wert aus `localStorage` zaehlte als Wahl. `localStorage` haengt am
     * Browser, nicht am Schirm — wer den Assistenten am Schreibtisch offen
     * laesst, bekam ihn am Telefon ueber der Liste. Siehe die Begruendung
     * unten im Effekt.
     *
     * `gewaehlt` heisst deshalb: auf DIESER Seite geholt. Nicht: irgendwann
     * einmal geholt.
     */
    const [gewaehlt, setGewaehlt] = useState(false);

    useEffect(() => {
        try {
            const gespeichert = localStorage.getItem(key);

            if (gespeichert !== null) {
                setSichtbar(gespeichert === 'true');
                /*
                 * KEIN `setGewaehlt(true)` — und das ist der Befund vom
                 * 30.10.2026 aus dem Rollout von v0.187.0 (#9134).
                 *
                 * Hier stand es, und es war naheliegend: Wer den Assistenten
                 * einmal geoeffnet hat, hat gewaehlt, und die Wahl steht im
                 * Speicher. Nur ist `localStorage` an den BROWSER gebunden und
                 * nicht an den Schirm — und derselbe Mensch sitzt mal am
                 * 1440er und mal am Telefon.
                 *
                 * Gemessen in CRM und Verwaltung am lebenden Element: Der am
                 * Schreibtisch offen gelassene Assistent (der Normalfall) lag
                 * am Telefon ueber der Mailliste, 707 px hoch ab y=121; die
                 * erste Listenzeile stand bei y=219 dahinter. Genau das, was
                 * am 29.10. schon einmal behoben wurde — nur kam es diesmal
                 * nicht aus der Vorgabe, sondern aus dem Speicher.
                 *
                 * `gewaehlt` beantwortet deshalb ab jetzt eine engere Frage:
                 * „Hat jemand den Assistenten AUF DIESER SEITE geholt?" Nur
                 * dann darf er auf einem schmalen Schirm etwas verdecken. Am
                 * Schreibtisch aendert das nichts: Dort haengt die Sichtbarkeit
                 * an `sichtbar`, und die kommt weiter aus dem Speicher.
                 */
            }
        } catch {
            // Private window or storage denied — then the default stands.
        }
    }, [key]);

    const setzen = useCallback(
        (wert: boolean) => {
            setSichtbar(wert);
            // Wer schaltet, hat gewaehlt — auch wenn der Speicher gleich
            // darauf abweist. Die Sitzung soll es trotzdem wissen.
            setGewaehlt(true);
            try {
                localStorage.setItem(key, String(wert));
            } catch {
                // Not storable — the session still remembers it.
            }
        },
        [key],
    );

    return {
        sichtbar,
        gewaehlt,
        zeigen: useCallback(() => setzen(true), [setzen]),
        verbergen: useCallback(() => setzen(false), [setzen]),
    };
}

export interface MailboxSidePanelProps {
    title: string;
    children: ReactNode;
    onClose?: () => void;
    /** Tailwind width class for the column. */
    width?: string;
    /** What the close button is called for screen readers. */
    closeLabel?: string;
}

export function MailboxSidePanel({ title, children, onClose, width = 'w-96', closeLabel = 'Ausblenden' }: MailboxSidePanelProps) {
    return (
        <div className={`flex ${width} min-w-0 shrink-0 flex-col overflow-hidden border-l`} data-slot="mailbox-side-panel">
            <div className="flex items-center justify-between border-b px-3 py-2">
                <span className="text-sm font-semibold">{title}</span>
                {onClose && (
                    <button type="button" className="text-gray-400 hover:text-gray-700" aria-label={closeLabel} onClick={onClose}>
                        ✕
                    </button>
                )}
            </div>
            {/* `min-h-0` so the content can scroll instead of pushing the column open. */}
            <div className="min-h-0 flex-1">{children}</div>
        </div>
    );
}

/**
 * The way back to a closed panel.
 *
 * Without it the panel is gone for good from the user's point of view — nothing
 * on screen says it can come back.
 */
export function MailboxSidePanelHandle({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <button
            type="button"
            className="fixed right-6 bottom-6 z-40 rounded-full bg-blue-600 px-4 py-3 text-sm font-medium text-white shadow-lg hover:bg-blue-700"
            aria-label={`${label} einblenden`}
            onClick={onClick}
        >
            {label}
        </button>
    );
}

export default MailboxSidePanel;
