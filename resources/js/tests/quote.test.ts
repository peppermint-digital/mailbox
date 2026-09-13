import { describe, expect, it } from 'vitest';
import { buildForwardQuote, buildReplyQuote, stripEmbeddedImages } from '../src/quote';

/**
 * Quoting the original, lifted from peppermint-manager (#5488).
 *
 * The stripping test is the one that matters: it stands for a real outage —
 * a reply that carried its own signature logos back to the server until the
 * request hit 13 MB and was rejected before the application saw it.
 */
const labels = {
    repliedOn: (date: string, sender: string) => `Am ${date} schrieb ${sender}:`,
    forwardHeadline: '---------- Weitergeleitete Nachricht ----------',
    from: 'Von:',
    date: 'Datum:',
    subject: 'Betreff:',
    to: 'An:',
};

const options = { labels, formatDate: (iso: string) => `[${iso}]` };

describe('stripEmbeddedImages', () => {
    it('replaces an embedded image with its caption', () => {
        const html = '<p>Hallo</p><img src="data:image/png;base64,AAAA" alt="Firmenlogo">';

        expect(stripEmbeddedImages(html)).toBe('<p>Hallo</p>[Firmenlogo]');
    });

    it('drops an embedded image that has no caption', () => {
        expect(stripEmbeddedImages('<img src="data:image/png;base64,AAAA">')).toBe('');
    });

    it('leaves a normal image alone', () => {
        // Only the payload is the problem. A linked image costs nothing.
        const html = '<img src="https://example.test/logo.png" alt="Logo">';

        expect(stripEmbeddedImages(html)).toBe(html);
    });

    it('removes an embedded background as well', () => {
        expect(stripEmbeddedImages('<div style="background: url(data:image/png;base64,AA)">'))
            .toBe('<div style="background: none">');
    });
});

describe('buildReplyQuote', () => {
    it('asks the product for the words', () => {
        const quote = buildReplyQuote(
            { date: '2026-09-01T10:00:00Z', fromAddress: 'kunde@example.test', fromName: 'Kunde', bodyHtml: '<p>Text</p>' },
            options,
        );

        expect(quote).toContain('Am [2026-09-01T10:00:00Z] schrieb Kunde &lt;kunde@example.test&gt;:');
        expect(quote).toContain('<p>Text</p>');
    });

    it('falls back to the plain-text body, keeping the line breaks', () => {
        const quote = buildReplyQuote(
            { date: '2026-09-01T10:00:00Z', fromAddress: 'a@example.test', bodyText: 'Zeile 1\nZeile 2' },
            options,
        );

        expect(quote).toContain('Zeile 1<br>Zeile 2');
    });

    it('shows a sender without a name as the bare address', () => {
        const quote = buildReplyQuote({ date: '2026-09-01T10:00:00Z', fromAddress: 'a@example.test' }, options);

        expect(quote).toContain('schrieb a@example.test:');
    });

    it('strips embedded images from the quoted body', () => {
        const quote = buildReplyQuote(
            { date: '2026-09-01T10:00:00Z', fromAddress: 'a@example.test', bodyHtml: '<img src="data:image/png;base64,AAAA" alt="Logo">' },
            options,
        );

        expect(quote).not.toContain('base64');
        expect(quote).toContain('[Logo]');
    });
});

describe('buildForwardQuote', () => {
    it('lists the header lines with the product labels', () => {
        const quote = buildForwardQuote(
            {
                date: '2026-09-01T10:00:00Z',
                fromAddress: 'a@example.test',
                subject: 'Angebot',
                toAddresses: [{ email: 'b@example.test', name: 'B' }, { email: 'c@example.test' }],
                bodyHtml: '<p>Text</p>',
            },
            options,
        );

        expect(quote).toContain('---------- Weitergeleitete Nachricht ----------');
        expect(quote).toContain('Betreff: Angebot');
        expect(quote).toContain('An: B &lt;b@example.test&gt;, c@example.test');
    });

    it('survives a forward without recipients', () => {
        const quote = buildForwardQuote(
            { date: '2026-09-01T10:00:00Z', fromAddress: 'a@example.test', subject: 'Angebot' },
            options,
        );

        expect(quote).toContain('An: ');
    });
});
