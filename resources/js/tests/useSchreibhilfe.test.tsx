import { describe, expect, it } from 'vitest';
import { useMemo } from 'react';
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { SignaturKnoten, ZitatKnoten } from '../src/editorKnoten';
import { buildReplyQuote } from '../src/quote';
import { wrapSignature } from '../src/signature';
import { useSchreibhilfe, type SchreibhilfeQuelle } from '../src/useSchreibhilfe';
import { click, render } from './support/render';

/**
 * Was der Schreibassistent anfassen darf — und was nicht (P18, 31.10.2026).
 *
 * Bastians Befund: „Grüßen" wurde zu „Grueßen", Leerzeilen fielen weg. Die
 * Ursache lag nicht im Assistenten, sondern darin, dass die Signatur fuer den
 * Editor kein eigenes Ding war (siehe `editorKnoten.test.ts`). Hier wird die
 * Wirkung gemessen: Laesst eine Korrektur des ganzen Rumpfes Signatur und
 * Zitat Zeichen fuer Zeichen stehen?
 *
 * Mit einem echten Editor, weil die Grenze aus `doc.descendants` kommt. Eine
 * Attrappe wuerde die Frage nicht beantworten, sondern voraussetzen.
 */

const SIGNATUR = '<p>Mit freundlichen Grüßen</p><p></p><p>Chris Stelljes</p>';

const ZITAT = buildReplyQuote(
    {
        date: '2026-10-31T10:00:00Z',
        fromName: 'Bastian Stelljes',
        fromAddress: 'bastian@peppermint-digital.de',
        bodyHtml: '<p>Bitte bestätigen Sie den Liefertermin</p>',
        bodyText: null,
    },
    { labels: { repliedOn: (d: string, s: string) => `Am ${d} schrieb ${s}:` } as never, formatDate: (d: string) => d },
);

/** Eine Quelle, die immer dasselbe antwortet — damit sichtbar ist, WAS ersetzt wurde. */
const QUELLE: SchreibhilfeQuelle = {
    verarbeiten: async () => 'KORRIGIERT',
};

function Probe({ inhalt, auswahlImText, tabuKnoten }: { inhalt: string; auswahlImText?: string; tabuKnoten?: string[] }) {
    const editor = useMemo(() => {
        const element = document.createElement('div');
        document.body.appendChild(element);

        const e = new Editor({
            element,
            extensions: [StarterKit.configure({ heading: { levels: [1, 2, 3] } }), SignaturKnoten, ZitatKnoten],
            content: inhalt,
        });

        if (auswahlImText) {
            /*
             * Eine Auswahl setzen, wie ein Mensch sie zieht: die Stelle im
             * Text suchen und von dort bis zu ihrem Ende markieren.
             */
            const stelle = e.state.doc.textBetween(0, e.state.doc.content.size, '\n').indexOf(auswahlImText);
            let von = -1;

            e.state.doc.descendants((knoten, position) => {
                if (von >= 0 || !knoten.isText) {
                    return;
                }

                const treffer = (knoten.text ?? '').indexOf(auswahlImText);

                if (treffer >= 0) {
                    von = position + treffer;
                }
            });

            if (von < 0) {
                throw new Error(`„${auswahlImText}" steht nicht im Text (Index ${stelle}).`);
            }

            e.commands.setTextSelection({ from: von, to: von + auswahlImText.length });
        }

        return e;
    }, [inhalt, auswahlImText]);

    const hilfe = useSchreibhilfe(editor as never, QUELLE, { tabuKnoten });

    return (
        <div>
            <button type="button" data-rolle="los" onClick={() => void hilfe.anwenden('correct')}>
                los
            </button>
            <output data-rolle="html">{editor.getHTML()}</output>
        </div>
    );
}

async function korrigiere(props: { inhalt: string; auswahlImText?: string; tahuKnoten?: string[] }): Promise<string> {
    const { container, unmount } = render(<Probe inhalt={props.inhalt} auswahlImText={props.auswahlImText} />);

    click(container.querySelector('[data-rolle="los"]')!);

    /* Die Quelle ist ein Versprechen; ein Takt reicht nicht immer. */
    for (let i = 0; i < 20; i++) {
        await new Promise((r) => setTimeout(r, 5));

        if ((container.querySelector('[data-rolle="html"]')?.textContent ?? '').includes('KORRIGIERT')) {
            break;
        }
    }

    const html = container.querySelector('[data-rolle="html"]')?.textContent ?? '';
    unmount();

    return html;
}

describe('eine Korrektur des ganzen Rumpfes', () => {
    it('laesst die Signatur Zeichen fuer Zeichen stehen — Bastians Fall', async () => {
        const html = await korrigiere({ inhalt: `<p>hallo bastian wie gehts</p>${wrapSignature(SIGNATUR)}` });

        expect(html).toContain('KORRIGIERT');
        // Woertlich, mit ß und mit der Leerzeile: genau das war kaputt.
        expect(html).toContain('Mit freundlichen Grüßen');
        expect(html).toContain('Chris Stelljes');
        expect(html).toContain('data-signature');
        expect(html).not.toContain('Grueßen');
    });

    it('laesst das Zitat Zeichen fuer Zeichen stehen — die Worte des ANDEREN', async () => {
        /*
         * Nicht mitgemessen, sondern eigens geprueft. Die Annahme „das Zitat
         * liegt ohnehin hinter der Signatur" waere eine Behauptung ueber eine
         * Reihenfolge, die das Produkt bestimmt — und wenn sie faellt, schreibt
         * der Assistent die Mail des Gegenuebers um.
         */
        const html = await korrigiere({ inhalt: `<p>ja passt</p>${ZITAT}` });

        expect(html).toContain('KORRIGIERT');
        expect(html).toContain('Bitte bestätigen Sie den Liefertermin');
        expect(html).toContain('Am 2026-10-31T10:00:00Z schrieb Bastian Stelljes');
        expect(html).toContain('data-quote');
    });

    it('schuetzt beide, auch wenn das Zitat VOR der Signatur steht', async () => {
        /*
         * Der Fall, der die zwei Knoten rechtfertigt: Die Grenze ist der
         * ERSTE Tabu-Knoten, also schuetzt sie hier das Zitat — und die
         * Signatur dahinter gleich mit.
         */
        const html = await korrigiere({ inhalt: `<p>ja passt</p>${ZITAT}${wrapSignature(SIGNATUR)}` });

        expect(html).toContain('KORRIGIERT');
        expect(html).toContain('Bitte bestätigen Sie den Liefertermin');
        expect(html).toContain('Mit freundlichen Grüßen');
    });
});

describe('eine Auswahl schlaegt das Tabu', () => {
    it('korrigiert markierten Text INNERHALB der Signatur', async () => {
        /*
         * Eine Entscheidung und kein Versehen: Wer den Text in seiner
         * Signatur markiert und um eine Korrektur bittet, hat genau das
         * gesagt. Das Tabu wendet ab, dass die Signatur MITGENOMMEN wird,
         * wenn jemand den Rumpf meint — es sperrt sie nicht gegen ihren
         * Besitzer.
         */
        const html = await korrigiere({
            inhalt: `<p>hallo bastian</p>${wrapSignature('<p>Mit freundlichen Gruessen</p><p>Chris</p>')}`,
            auswahlImText: 'Mit freundlichen Gruessen',
        });

        expect(html).toContain('KORRIGIERT');
        // Der Rumpf bleibt, es war ja die Signatur gemeint.
        expect(html).toContain('hallo bastian');
        expect(html).toContain('data-signature');
    });
});
