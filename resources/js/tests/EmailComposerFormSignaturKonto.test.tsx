import { describe, expect, it, vi } from 'vitest';
import { EmailComposerForm } from '../src/EmailComposerForm';
import { germanLabels } from '../src/germanLabels';
import { render } from './support/render';

/**
 * Welches Postfach die Signaturen bestimmt (01.11.2026).
 *
 * ## Der Befund dahinter
 *
 * Gemessen am 01.11.2026 in Produktion (AI Brain, Postfach 3 mit einer
 * Vorgabe-Signatur, Route und Vorrat beide nachweislich in Ordnung): Konto
 * gewaehlt, Signatur vorhanden, Zeile trotzdem weg.
 *
 * Die Ursache war eine Vorgabe: `signatureAccountId` stand auf `null`, und
 * `VerfassenFeld` gibt die Eigenschaft nicht weiter. Das Formular lud also
 * immer mit `undefined` — worauf die Quelle des Pakets mit einer LEEREN Liste
 * antwortet, weil `…/undefined/signatures` eine 404 waere. Zwei fuer sich
 * richtige Entscheidungen, die zusammen eine Faehigkeit abschalten.
 *
 * Nicht aufgefallen ist das, weil der einzige Aufrufer mit Signaturen bis
 * dahin der Projekt-Manager war — und DESSEN Haken laedt bei `undefined`
 * absichtlich ALLE Signaturen der Person.
 */
const GRUNDWERTE = {
    labels: germanLabels.verfassen,
    editor: () => null,
    recipients: { all: () => Promise.resolve([]), search: () => Promise.resolve([]) },
    to: [],
    onToChange: () => {},
    subject: '',
    onSubjectChange: () => {},
    bodyHtml: '',
    onBodyHtmlChange: () => {},
    onSend: () => {},
    onCancel: () => {},
} as never;

function quelle() {
    return {
        signatures: [],
        load: vi.fn(async () => undefined),
        defaultSignature: () => null,
    };
}

describe('das Postfach, nach dessen Signaturen gefragt wird', () => {
    it('ist ohne weitere Angabe das gewaehlte Absenderkonto', () => {
        /*
         * DIE Zusicherung. Ein Produkt, das `accountId` fuehrt — und das sind
         * alle vier —, bekommt die Signaturen dieses Postfachs, ohne eine
         * zweite Eigenschaft zu setzen.
         */
        const vorrat = quelle();

        const { unmount } = render(<EmailComposerForm {...GRUNDWERTE} signatureSource={vorrat} accountId={3} />);

        expect(vorrat.load).toHaveBeenCalledWith(3);
        unmount();
    });

    it('bleibt das ausdruecklich genannte, auch wenn ein Absenderkonto gewaehlt ist', () => {
        /*
         * Beim Antworten und Weiterleiten ist das gemeinte Postfach das der
         * NACHRICHT, und das muss nicht das Absenderkonto des Formulars sein.
         * Genau deshalb gibt es die Eigenschaft weiter.
         */
        const vorrat = quelle();

        const { unmount } = render(
            <EmailComposerForm {...GRUNDWERTE} signatureSource={vorrat} accountId={3} signatureAccountId={9} />,
        );

        expect(vorrat.load).toHaveBeenCalledWith(9);
        unmount();
    });

    it('fragt ohne jedes Postfach weiterhin ohne Angabe', () => {
        /*
         * Solange niemand ein Absenderkonto gewaehlt hat, gibt es nichts zu
         * fragen. Die Quelle des Pakets antwortet darauf leer, der Haken des
         * Managers mit allen Signaturen der Person — beides bleibt richtig,
         * und beides entscheidet nicht dieses Formular.
         */
        const vorrat = quelle();

        const { unmount } = render(<EmailComposerForm {...GRUNDWERTE} signatureSource={vorrat} />);

        expect(vorrat.load).toHaveBeenCalledWith(undefined);
        unmount();
    });
});
