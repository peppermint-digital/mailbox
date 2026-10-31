import { describe, expect, it, vi } from 'vitest';
import { RichTextEditor } from '../src/RichTextEditor';
import { VerfassenFeld } from '../src/VerfassenFeld';
import { germanLabels } from '../src/germanLabels';
import { render } from './support/render';

/**
 * Schreibassistent und Spracheingabe im Editor (31.10.2026).
 *
 * Die Knöpfe hängen an der FÄHIGKEIT, nicht an einem Schalter — dieselbe
 * Regel wie beim Anhängen und wie bei den Mail-Knöpfen ohne Sendeweg: Ein
 * Knopf ohne Wirkung sieht aus wie ein Defekt, nicht wie eine fehlende
 * Funktion.
 */
const QUELLE = { verarbeiten: vi.fn().mockResolvedValue('neu') };
const STIMME = { abtippen: vi.fn().mockResolvedValue('gesprochen') };

function editor(zusatz: Record<string, unknown> = {}) {
    return render(<RichTextEditor labels={germanLabels.editor} value="<p>Text</p>" {...zusatz} />);
}

describe('die zwei Dienste am Editor', () => {
    it('zeigt KEINEN der beiden, solange kein Dienst dahintersteht', () => {
        const { container, unmount } = editor();

        expect(container.querySelector('[data-slot="editor-schreibhilfe"]')).toBeNull();
        expect(container.querySelector('[data-slot="editor-diktat"]')).toBeNull();
        unmount();
    });

    it('zeigt den Assistenten, sobald eine Quelle da ist', () => {
        const { container, unmount } = editor({ schreibhilfe: QUELLE });

        expect(container.querySelector('[data-slot="editor-schreibhilfe"]')).not.toBeNull();
        // Und die Spracheingabe NICHT — die beiden hängen an je eigenen Diensten.
        expect(container.querySelector('[data-slot="editor-diktat"]')).toBeNull();
        unmount();
    });

    it('zeigt die Spracheingabe, sobald eine Quelle da ist', () => {
        const { container, unmount } = editor({ diktat: STIMME });

        expect(container.querySelector('[data-slot="editor-diktat"]')).not.toBeNull();
        expect(container.querySelector('[data-slot="editor-schreibhilfe"]')).toBeNull();
        unmount();
    });

    it('laesst dem Produkt seinen eigenen Platz daneben', () => {
        /*
         * `extraActions` bleibt — für das, was WIRKLICH nur ein Produkt kann.
         * Bis zum 31.10.2026 hingen Assistent und Spracheingabe dort, und
         * genau deshalb hatten drei Produkte sie nicht.
         */
        const { container, unmount } = editor({
            schreibhilfe: QUELLE,
            extraActions: () => <button data-slot="eigener-knopf">Eigenes</button>,
        });

        expect(container.querySelector('[data-slot="editor-schreibhilfe"]')).not.toBeNull();
        expect(container.querySelector('[data-slot="eigener-knopf"]')).not.toBeNull();
        unmount();
    });
});

describe('VerfassenFeld reicht die Dienste an seinen Vorgabe-Editor durch', () => {
    /**
     * Gemeldet von `mail-rollout3` beim Nachziehen: Die Eigenschaften kamen an
     * `VerfassenFeld` an und endeten dort. Sie wurden an `EmailComposerForm`
     * weitergereicht — und der kennt sie nicht, es sind Eigenschaften des
     * EDITORS.
     *
     * Still gescheitert: kein Fehler, kein Übersetzerfehler, nur kein Knopf.
     * Ohne diese Zusicherung baut sich jedes Produkt seinen Editor selbst
     * zusammen, und aus einer Zeile werden dreimal vier.
     */
    const PFLICHT = {
        to: [],
        onToChange: () => {},
        subject: '',
        onSubjectChange: () => {},
        bodyHtml: '',
        onBodyHtmlChange: () => {},
        onSend: () => {},
        onCancel: () => {},
        recipients: { all: () => Promise.resolve([]), search: () => Promise.resolve([]) },
    } as never;

    it('zeigt den Assistenten, wenn die Huelle eine Quelle bekommt', () => {
        const { container, unmount } = render(<VerfassenFeld {...PFLICHT} schreibhilfe={QUELLE} />);

        expect(container.querySelector('[data-slot="editor-schreibhilfe"]')).not.toBeNull();
        unmount();
    });

    it('zeigt die Spracheingabe genauso', () => {
        const { container, unmount } = render(<VerfassenFeld {...PFLICHT} diktat={STIMME} />);

        expect(container.querySelector('[data-slot="editor-diktat"]')).not.toBeNull();
        unmount();
    });

    it('zeigt ohne Quellen weiterhin keinen von beiden', () => {
        const { container, unmount } = render(<VerfassenFeld {...PFLICHT} />);

        expect(container.querySelector('[data-slot="editor-schreibhilfe"]')).toBeNull();
        expect(container.querySelector('[data-slot="editor-diktat"]')).toBeNull();
        unmount();
    });

    it('schiebt einem EIGENEN Editor nichts unter', () => {
        /*
         * Wer seinen Editor mitbringt, bekommt die Dienste nicht von dieser
         * Hülle — dann gehören sie zu seinem Editor. Sonst hätte ein Produkt
         * zwei Stellen, die denselben Knopf zeichnen könnten.
         */
        const { container, unmount } = render(
            <VerfassenFeld {...PFLICHT} schreibhilfe={QUELLE} editor={() => <div data-slot="eigener-editor" />} />,
        );

        expect(container.querySelector('[data-slot="eigener-editor"]')).not.toBeNull();
        expect(container.querySelector('[data-slot="editor-schreibhilfe"]')).toBeNull();
        unmount();
    });
});
