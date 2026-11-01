import { useMemo } from 'react';
import { germanLabels } from './germanLabels';
import { InlineReplyPanel, type InlineReplyLabels, type InlineReplyPanelProps } from './InlineReplyPanel';
import { VerfassenFeld, type VerfasserDienste, type VerfassenFeldProps } from './VerfassenFeld';

/**
 * Antworten direkt im Postfach, statt auf eine eigene Seite zu springen (#8377).
 *
 * Diese Hülle stand dreimal im Bestand — CRM, Verwaltung, AI Brain —, auf
 * Token-Ebene zu **100,0 %** identisch, und sie importierte nichts aus dem
 * Produkt. Drei Dateien, die dasselbe Paketteil mit denselben deutschen
 * Wörtern verdrahten, sind eine Datei dieses Pakets.
 *
 * Gesendet wird NICHT hier: Das Panel reicht die fertige Antwort an `onSend`,
 * weil nur die Seite darüber Ordner und Kennung der Ursprungsnachricht kennt.
 *
 * ## Die Dienste reisen seit dem 01.11.2026 mit
 *
 * Bis dahin hing hier `composer={VerfassenFeld}` ohne jeden Durchgang. Was ein
 * Produkt an seinem Verfassen-Feld hatte, war damit in der Antwort weg:
 * gemessen 20 Editor-Knöpfe in AI Brain, CRM und Verwaltung — kein
 * Schreibassistent, keine Spracheingabe, keine Signatur. Siehe
 * {@link VerfasserDienste}.
 */
export const antwortTexte: InlineReplyLabels = {
    ...germanLabels.antwort,
    quote: germanLabels.zitat,
    body: germanLabels.rumpf,
};

export type InlineAntwortProps = Omit<InlineReplyPanelProps, 'labels' | 'composer'> & VerfasserDienste;

export function InlineAntwort({
    schreibhilfe,
    schreibhilfeTabu,
    diktat,
    signatureSource,
    recipients,
    ...eigenschaften
}: InlineAntwortProps) {
    /*
     * Gemerkt, und zwar an den EINZELNEN Diensten statt an einem Objekt:
     * Ein Produkt, das `verfasserDienste={{ schreibhilfe }}` direkt in sein
     * JSX schreibt, baut das Objekt bei jedem Zeichnen neu. Hinge das Merken
     * daran, wäre der Verfasser für React jedes Mal ein ANDERER Typ — er
     * würde ab- und neu aufgebaut, und nach jedem Tastendruck wäre die
     * Schreibmarke weg. Mit den Einzelwerten passiert das nur, wenn sich
     * wirklich ein Dienst ändert.
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

    return <InlineReplyPanel {...eigenschaften} composer={Verfasser as never} labels={antwortTexte} />;
}

export default InlineAntwort;
