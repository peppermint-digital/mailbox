import { describe, expect, it } from 'vitest';
import { htmlZuText, textZuHtml } from '../src/PlainTextEditor';

/**
 * Der Rueckfall-Editor (22.09.2026).
 *
 * Die Umrechnung ist der ganze Baustein — und sie kann still falsch sein:
 * Ein `<` im Text, das ungeschuetzt ins HTML geht, macht aus einem Satz
 * Auszeichnung.
 */
describe('PlainTextEditor', () => {
    it('macht aus Leerzeilen Absaetze und aus Umbruechen <br>', () => {
        expect(textZuHtml('Guten Tag\nAnna\n\nViele Grüße')).toBe('<p>Guten Tag<br>Anna</p>\n<p>Viele Grüße</p>');
    });

    it('schuetzt spitze Klammern, statt sie zu Auszeichnung zu machen', () => {
        // Sonst wird aus „5 < 7" im Zweifel ein offenes Tag, und der Rest der
        // Mail verschwindet in etwas, das der Empfaenger nie sieht.
        expect(textZuHtml('5 < 7 & mehr')).toBe('<p>5 &lt; 7 &amp; mehr</p>');
    });

    it('laesst leere Absaetze weg', () => {
        expect(textZuHtml('Eins\n\n\n\nZwei')).toBe('<p>Eins</p>\n<p>Zwei</p>');
    });

    it('macht aus nichts nichts', () => {
        expect(textZuHtml('   \n\n  ')).toBe('');
        expect(htmlZuText('')).toBe('');
    });

    it('findet zurueck zum Text', () => {
        const text = 'Guten Tag\nAnna\n\nViele Grüße';

        expect(htmlZuText(textZuHtml(text))).toBe(text);
    });

    it('holt auch aus fremdem HTML noch lesbaren Text', () => {
        // Verlustbehaftet, und das ist bekannt: Wer diesen Editor benutzt, hat
        // keinen anderen.
        expect(htmlZuText('<p><strong>Hallo</strong> Welt</p>')).toBe('Hallo Welt');
    });
});
