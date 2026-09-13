/**
 * Builds the quoted original underneath a reply or a forward.
 *
 * The same string is shown in the composer and sent along, so what the sender
 * sees is what the recipient gets. That is why this is one function and not
 * two that drift apart.
 *
 * ## Why the words come from outside
 *
 * "Am ... schrieb ...", the labels of a forward header, the format of a date —
 * all of it is user-facing text, and user-facing text belongs to the product,
 * in the product's language. The package builds the structure and asks for the
 * words.
 */

export interface QuoteAddress {
    email: string;
    name?: string | null;
}

export interface ReplyQuoteSource {
    date: string;
    fromName?: string | null;
    fromAddress: string;
    bodyHtml?: string | null;
    bodyText?: string | null;
}

export interface ForwardQuoteSource extends ReplyQuoteSource {
    subject: string;
    toAddresses?: QuoteAddress[];
}

export interface QuoteLabels {
    /** e.g. (dateText, sender) => `Am ${dateText} schrieb ${sender}:` */
    repliedOn: (dateText: string, sender: string) => string;
    forwardHeadline: string;
    from: string;
    date: string;
    subject: string;
    to: string;
}

export interface QuoteOptions {
    labels: QuoteLabels;
    /** The product decides the locale — the package has no business guessing it. */
    formatDate: (iso: string) => string;
    /** Override the look of the quote block. */
    style?: string;
}

export const DEFAULT_QUOTE_STYLE =
    'border-left: 3px solid #ccc; padding-left: 12px; margin-left: 0; color: #666;';

/** Images the original carries as base64 inside the HTML. */
const EMBEDDED_IMAGE = /<img\b[^>]*?\bsrc\s*=\s*(?:"data:[^"]*"|'data:[^']*'|data:[^\s>]*)[^>]*>/gi;
const EMBEDDED_BACKGROUND = /url\(\s*(['"]?)data:[^)]*\1\s*\)/gi;
const ALT_TEXT = /\balt\s*=\s*(["'])(.*?)\1/i;

/**
 * Strips embedded image data out of the quoted original.
 *
 * Replying used to send the original back to the server unchanged — including
 * the images the server had just delivered itself. One thread with signature
 * logos reached 13 MB and was rejected by the web server before the
 * application ever saw it (measured 10.08.2026 in peppermint-manager).
 *
 * The recipient loses nothing that matters: instead of the logo they get its
 * caption, and the original is sitting in their own mailbox anyway.
 */
export function stripEmbeddedImages(html: string): string {
    return html
        .replace(EMBEDDED_IMAGE, (tag) => {
            const alt = ALT_TEXT.exec(tag)?.[2]?.trim();

            return alt ? `[${alt}]` : '';
        })
        .replace(EMBEDDED_BACKGROUND, 'none');
}

function formatAddress(email: string, name?: string | null): string {
    return name ? `${name} &lt;${email}&gt;` : email;
}

function originalBody(source: ReplyQuoteSource): string {
    return stripEmbeddedImages(source.bodyHtml || source.bodyText?.replace(/\n/g, '<br>') || '');
}

export function buildReplyQuote(source: ReplyQuoteSource, options: QuoteOptions): string {
    const sender = formatAddress(source.fromAddress, source.fromName);
    const style = options.style ?? DEFAULT_QUOTE_STYLE;

    return `<br><br><div style="${style}">
        <p>${options.labels.repliedOn(options.formatDate(source.date), sender)}</p>
        ${originalBody(source)}
    </div>`;
}

export function buildForwardQuote(source: ForwardQuoteSource, options: QuoteOptions): string {
    const sender = formatAddress(source.fromAddress, source.fromName);
    const recipients = (source.toAddresses ?? []).map((a) => formatAddress(a.email, a.name)).join(', ');
    const style = options.style ?? DEFAULT_QUOTE_STYLE;
    const l = options.labels;

    return `<br><br><div style="${style}">
        <p>${l.forwardHeadline}<br>
        ${l.from} ${sender}<br>
        ${l.date} ${options.formatDate(source.date)}<br>
        ${l.subject} ${source.subject}<br>
        ${l.to} ${recipients}</p>
        ${originalBody(source)}
    </div>`;
}
