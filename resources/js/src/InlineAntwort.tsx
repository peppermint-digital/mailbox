import { germanLabels } from './germanLabels';
import { InlineReplyPanel, type InlineReplyLabels, type InlineReplyPanelProps } from './InlineReplyPanel';
import { VerfassenFeld } from './VerfassenFeld';

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
 */
export const antwortTexte: InlineReplyLabels = {
    ...germanLabels.antwort,
    quote: germanLabels.zitat,
    body: germanLabels.rumpf,
};

export type InlineAntwortProps = Omit<InlineReplyPanelProps, 'labels' | 'composer'>;

export function InlineAntwort(eigenschaften: InlineAntwortProps) {
    return <InlineReplyPanel {...eigenschaften} composer={VerfassenFeld as never} labels={antwortTexte} />;
}

export default InlineAntwort;
