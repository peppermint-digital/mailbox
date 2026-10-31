import { describe, expect, it } from 'vitest';
import { EmailComposerForm } from '../src/EmailComposerForm';
import { germanLabels } from '../src/germanLabels';
import { render } from './support/render';

/**
 * Was der Entwurf mitbringt, steht auf der Seite — in ALLEN vier Produkten.
 *
 * Bug #1155, zweite Hälfte: Die Anhänge eines wiederaufgenommenen Entwurfs
 * gehen beim Senden mit, waren aber nirgends zu sehen. Der Absender musste dem
 * Verhalten vertrauen, statt es zu prüfen — bei einer Mail, die eine Rechnung
 * ankündigt, zu wenig.
 *
 * Behoben in v0.189.0 — und zwar nur halb, was `mail-rollout3` am 31.10.2026
 * am lebenden CRM gemessen hat: Die Seitenantwort war richtig
 * (`draft.attachments` gefüllt), auf dem Schirm stand nichts.
 */
const GRUNDWERTE = {
    labels: germanLabels.verfassen,
    editor: () => null,
    recipients: { all: () => Promise.resolve([]), search: () => Promise.resolve([]) },
    signatureSource: { signatures: [], load: async () => undefined, defaultSignature: () => null },
    to: [],
    onToChange: () => {},
    subject: '',
    onSubjectChange: () => {},
    bodyHtml: '',
    onBodyHtmlChange: () => {},
    onSend: () => {},
    onCancel: () => {},
} as never;

describe('die Anhänge eines Entwurfs', () => {
    it('stehen auf der Seite, auch wenn das Produkt gar nichts hochladen kann', () => {
        /*
         * DIE Zusicherung. Der Block stand bis zum 31.10.2026 innerhalb von
         * `{allowAttachments && …}`, und dessen Vorgabe ist `false`. CRM,
         * Verwaltung und AI Brain geben die Eigenschaft nicht mit — ihre
         * Verfassen-Seiten senden JSON und können keine Dateien hochladen.
         *
         * Hier wird `allowAttachments` deshalb bewusst NICHT gesetzt: Genau
         * dieser Fall war in drei von vier Produkten blind.
         */
        const { container, unmount } = render(
            <EmailComposerForm {...GRUNDWERTE} vorhandeneAnhaenge={[{ filename: 'Rechnung.pdf', size: 2048 }]} />,
        );

        const liste = container.querySelector('[data-slot="composer-vorhandene-anhaenge"]');

        expect(liste, 'ohne allowAttachments war die Liste unsichtbar').not.toBeNull();
        expect(liste!.textContent).toContain('Rechnung.pdf');
        unmount();
    });

    it('tragen kein Entfernen-Kreuz — die Datei liegt im Entwurf, nicht hier', () => {
        // Ein Kreuz, das die Datei nicht wegbekommt, wäre eine Lüge.
        const { container, unmount } = render(
            <EmailComposerForm {...GRUNDWERTE} vorhandeneAnhaenge={[{ filename: 'Rechnung.pdf', size: 2048 }]} />,
        );

        expect(container.querySelector('[data-slot="composer-vorhandene-anhaenge"] button')).toBeNull();
        unmount();
    });

    it('bleiben weg, wenn der Entwurf keine hat', () => {
        // Eine leere Überschrift „Anhänge" wäre eine Frage, die niemand stellt.
        const { container, unmount } = render(<EmailComposerForm {...GRUNDWERTE} />);

        expect(container.querySelector('[data-slot="composer-vorhandene-anhaenge"]')).toBeNull();
        unmount();
    });
});
