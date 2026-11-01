import { describe, expect, it, vi } from 'vitest';
import { InlineAntwort } from '../src/InlineAntwort';
import { WeiterleitenFeld } from '../src/inertia/WeiterleitenFeld';
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

describe('Antwort und Weiterleitung reichen die Dienste genauso durch (01.11.2026)', () => {
    /**
     * Der Befund, der diesen Block ausgelöst hat.
     *
     * Die zwei Hüllen des Postfachs setzten `composer={VerfassenFeld}` ohne
     * jeden Durchgang. Der Block darüber sichert also seit dem 31.10.2026 eine
     * Strecke zu, die nur die Verfassen-SEITE benutzt — und in der Antwort
     * endete dieselbe Fähigkeit im Nichts.
     *
     * Gemessen am 01.11.2026 in Produktion, am Antwortfeld derselben Mail:
     * AI Brain, CRM und Verwaltung je **20** Editor-Knöpfe ohne Assistent und
     * Mikrofon; der Projekt-Manager hatte beide — weil er die Hülle umgeht und
     * sein eigenes Formular einhängt.
     *
     * Dass es niemandem auffiel, liegt an der Form des Fehlschlags: Es fehlt
     * ein Knopf. Es gibt keine Meldung, keinen Übersetzerfehler, keinen roten
     * Test — die Antwort sieht vollständig aus.
     */
    const ANTWORT_PFLICHT = {
        account: { id: 1, name: 'Büro', email: 'buero@example.test' },
        message: { uid: 7, subject: 'Anfrage', from_address: 'kundin@example.test', date: '2026-11-01T08:00:00Z' },
        replyAll: false,
        sending: false,
        onSend: () => {},
        onCancel: () => {},
    } as never;

    const WEITERLEITEN_PFLICHT = {
        account: { id: 1, name: 'Büro', email: 'buero@example.test' },
        source: { type: 'imap', folder: 'INBOX', uid: 7 },
        subject: 'Anfrage',
        fromAddress: 'kundin@example.test',
        date: '2026-11-01T08:00:00Z',
        endpoints: { forward: '/mailbox/1/forward' },
        onSent: () => {},
        onCancel: () => {},
    } as never;

    it('zeigt beide Knöpfe im Antwortfeld, wenn das Produkt die Dienste mitgibt', () => {
        const { container, unmount } = render(<InlineAntwort {...ANTWORT_PFLICHT} schreibhilfe={QUELLE} diktat={STIMME} />);

        expect(container.querySelector('[data-slot="editor-schreibhilfe"]')).not.toBeNull();
        expect(container.querySelector('[data-slot="editor-diktat"]')).not.toBeNull();
        unmount();
    });

    it('zeigt im Antwortfeld ohne Dienste weiterhin keinen von beiden', () => {
        // Die Gegenprobe: Ein Produkt ohne Assistenten bekommt keinen Knopf
        // untergeschoben, nur weil die Strecke jetzt offen ist.
        const { container, unmount } = render(<InlineAntwort {...ANTWORT_PFLICHT} />);

        expect(container.querySelector('[data-slot="editor-schreibhilfe"]')).toBeNull();
        expect(container.querySelector('[data-slot="editor-diktat"]')).toBeNull();
        unmount();
    });

    it('zeigt beide Knöpfe beim Weiterleiten', () => {
        const { container, unmount } = render(<WeiterleitenFeld {...WEITERLEITEN_PFLICHT} schreibhilfe={QUELLE} diktat={STIMME} />);

        expect(container.querySelector('[data-slot="editor-schreibhilfe"]')).not.toBeNull();
        expect(container.querySelector('[data-slot="editor-diktat"]')).not.toBeNull();
        unmount();
    });

    it('zeigt beim Weiterleiten ohne Dienste keinen von beiden', () => {
        const { container, unmount } = render(<WeiterleitenFeld {...WEITERLEITEN_PFLICHT} />);

        expect(container.querySelector('[data-slot="editor-schreibhilfe"]')).toBeNull();
        expect(container.querySelector('[data-slot="editor-diktat"]')).toBeNull();
        unmount();
    });

    it('nimmt die Signaturen des Produkts mit in die Antwort', () => {
        /*
         * Derselbe Durchgang trägt die Signatur. Heute hat nur der
         * Projekt-Manager welche — und nur in seiner eigenen Hülle. Nach dem
         * Umzug der Signaturen nach AI Brain hängt hier die Zeile, die alle
         * vier zeigen sollen; ohne diese Zusicherung bliebe sie in Antwort und
         * Weiterleitung wieder weg.
         */
        const signaturen = {
            signatures: [{ id: 1, name: 'Standard', body_html: '<p>-- <br>Büro</p>', is_default: true }],
            load: async () => undefined,
            defaultSignature: () => ({ id: 1, name: 'Standard', body_html: '<p>-- <br>Büro</p>', is_default: true }),
        };

        const { container, unmount } = render(<InlineAntwort {...ANTWORT_PFLICHT} signatureSource={signaturen as never} />);

        expect(container.textContent).toContain(germanLabels.verfassen.signature);
        unmount();
    });
});
