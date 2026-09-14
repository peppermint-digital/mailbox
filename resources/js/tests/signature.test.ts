import { describe, expect, it } from 'vitest';
import { buildBodyWithSignature, stripSignature, swapSignature, wrapSignature } from '../src/signature';

/**
 * Signature surgery, lifted from peppermint-manager (#5488).
 *
 * The cases that matter are the ones where a wrong answer is invisible:
 * a second signature appended instead of the first replaced, or a cursor that
 * lands inside the block the writer must not edit.
 */
const SIG = '<p>Viele Grüße</p>';

describe('wrapSignature', () => {
    it('marks the block so an editor can isolate it', () => {
        expect(wrapSignature(SIG)).toBe(`<div data-signature="1">${SIG}</div>`);
    });
});

describe('buildBodyWithSignature', () => {
    it('puts a paragraph in front for the writer cursor', () => {
        // Without it the cursor starts inside the signature — the one place it
        // must not be.
        expect(buildBodyWithSignature(SIG)).toBe(`<p><br></p><div data-signature="1">${SIG}</div>`);
    });
});

describe('swapSignature', () => {
    it('replaces an existing block and leaves the text alone', () => {
        const body = `<p>Mein Text</p><div data-signature="1">${SIG}</div>`;

        const result = swapSignature(body, '<p>Neu</p>');

        expect(result).toContain('<p>Mein Text</p>');
        expect(result).toContain('<div data-signature="1"><p>Neu</p></div>');
        expect(result).not.toContain('Viele Grüße');
    });

    it('recognises the old comment markers too', () => {
        // Drafts written before the change are sitting in mailboxes. Not
        // recognising them appends a SECOND signature instead of replacing.
        const body = `<p>Text</p><!--signature-start--><p>Alt</p><!--signature-end-->`;

        const result = swapSignature(body, '<p>Neu</p>');

        expect(result).not.toContain('Alt');
        expect(result.match(/data-signature/g)).toHaveLength(1);
    });

    it('appends to an empty body without an extra paragraph', () => {
        expect(swapSignature('', '<p>Neu</p>')).toBe('<div data-signature="1"><p>Neu</p></div>');
    });

    it('adds a cursor paragraph when the body does not end in one', () => {
        const result = swapSignature('<blockquote>Zitat</blockquote>', '<p>Neu</p>');

        expect(result).toBe('<blockquote>Zitat</blockquote><p><br></p><div data-signature="1"><p>Neu</p></div>');
    });

    it('does not add a second paragraph when one is already there', () => {
        const result = swapSignature('<p>Text</p>', '<p>Neu</p>');

        expect(result).toBe('<p>Text</p><div data-signature="1"><p>Neu</p></div>');
    });

    it('survives nested markup inside the signature', () => {
        // The reason this uses a DOM parser and not a regular expression.
        const body = '<div data-signature="1"><div><table><tr><td>Alt</td></tr></table></div></div>';

        expect(swapSignature(body, '<p>Neu</p>')).toBe('<div data-signature="1"><p>Neu</p></div>');
    });
});

describe('stripSignature', () => {
    it('removes both the new and the old form', () => {
        const body = `<p>Text</p><div data-signature="1">${SIG}</div><!--signature-start--><p>Alt</p><!--signature-end-->`;

        expect(stripSignature(body)).toBe('<p>Text</p>');
    });

    it('leaves a body without a signature untouched', () => {
        expect(stripSignature('<p>Nur Text</p>')).toBe('<p>Nur Text</p>');
    });
});
