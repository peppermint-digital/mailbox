import { useMemo } from 'react';
import { ForwardComposer, type ForwardComposerLabels, type ForwardComposerProps } from './ForwardComposer';
import { germanLabels } from '../germanLabels';
import { VerfassenFeld, type VerfasserDienste, type VerfassenFeldProps } from '../VerfassenFeld';

/**
 * Weiterleiten direkt im Postfach — mit den Dateien der Ursprungsnachricht (#8377).
 *
 * Auch diese Hülle stand dreimal im Bestand, zu **100,0 %** identisch. Der
 * einzige Bezug zum Produkt war die Art, eine Erfolgsmeldung zu zeigen —
 * daraus ist jetzt eine Eigenschaft geworden.
 *
 * ## Warum das Paket die Anhänge nicht durch den Browser schickt
 *
 * Es reicht einen Zeiger auf die Ursprungsnachricht (Ordner + Kennung), keine
 * Nutzlast. Eine Weiterleitung mit 20 MB Anhang reist damit nicht zweimal
 * durch die Leitung.
 *
 * Bis zum 14.10.2026 führte „Weiterleiten" in den Produkten auf die
 * Verfassen-Seite: Zitat über den Sitzungsspeicher, Empfänger leer — und die
 * Anhänge blieben liegen. Wer eine Rechnung weiterleitete, schickte den Satz
 * darüber und sonst nichts, und sah keinen Unterschied zu einer geglückten
 * Weiterleitung.
 */
export const weiterleitTexte: ForwardComposerLabels = {
    ...germanLabels.weiterleiten,
    quote: germanLabels.zitat,
    body: germanLabels.rumpf,
};

export type WeiterleitenFeldProps = Omit<ForwardComposerProps, 'labels' | 'composer' | 'notify' | 'endpoints'> &
    VerfasserDienste & {
        endpoints: { forward: string };
        /**
         * Wie eine Erfolgsmeldung aussieht, entscheidet das Produkt.
         *
         * Ohne Angabe wird nichts gemeldet — stiller als vorher, aber nicht falsch:
         * Das Formular schließt sich, und die Liste wird neu geholt.
         */
        melden?: (nachricht: string) => void;
    };

export function WeiterleitenFeld({
    melden,
    schreibhilfe,
    schreibhilfeTabu,
    diktat,
    signatureSource,
    recipients,
    ...eigenschaften
}: WeiterleitenFeldProps) {
    /*
     * Dieselbe Hülle wie in {@link InlineAntwort}, aus demselben Grund: Die
     * Dienste des Produkts gehören auch in die Weiterleitung, und gemerkt
     * wird an den Einzelwerten, damit ein neu gebautes Objekt im JSX des
     * Produkts den Editor nicht bei jedem Zeichnen neu aufbaut.
     */
    const Verfasser = useMemo(
        () =>
            function Verfasser(durchgereicht: VerfassenFeldProps) {
                return (
                    <VerfassenFeld
                        {...durchgereicht}
                        schreibhilfe={schreibhilfe}
                        schreibhilfeTabu={schreibhilfeTabu}
                        diktat={diktat}
                        signatureSource={signatureSource}
                        recipients={recipients}
                    />
                );
            },
        [schreibhilfe, schreibhilfeTabu, diktat, signatureSource, recipients],
    );

    return (
        <ForwardComposer
            {...eigenschaften}
            composer={Verfasser as never}
            labels={weiterleitTexte}
            notify={{ success: (nachricht) => melden?.(nachricht) }}
        />
    );
}

export default WeiterleitenFeld;
