/**
 * Putting a signature into a mail body, taking it out, swapping it.
 *
 * Pure functions, no React: they are used outside components too, and their
 * whole job is string surgery on the body a rich-text editor produces.
 *
 * ## The marker
 *
 * A signature lives in `<div data-signature="1">`. An editor can treat that as
 * an isolated, non-editable node — which is what keeps normal typing (and an
 * AI correction pass) from reaching into the signature block.
 *
 * The older HTML-comment markers are still accepted on the way in: drafts
 * written before the change are sitting in people's mailboxes, and a swap that
 * does not recognise them would append a second signature instead of replacing
 * the first.
 *
 * ## DOM required
 *
 * These parse with `DOMParser`, so they belong to the browser half. Doing it
 * with regular expressions would break on the first nested `<div>` — and a
 * signature is exactly the kind of HTML that nests.
 */

/** Legacy marker — may still occur in drafts written before the change. */
const LEGACY_SIGNATURE = /<!--signature-start-->[\s\S]*?<!--signature-end-->/g;

function wrapBlock(html: string): string {
    return `<div data-signature="1">${html}</div>`;
}

function parseBody(body: string): { doc: Document; container: HTMLElement } {
    const doc = new DOMParser().parseFromString(`<!doctype html><body>${body}</body>`, 'text/html');

    return { doc, container: doc.body };
}

/** Wrap signature HTML in the marker structure. */
export function wrapSignature(html: string): string {
    return wrapBlock(html);
}

/**
 * The starting body of a reply: a leading paragraph for the writer's cursor,
 * then the signature. Without that paragraph the cursor lands inside the
 * signature block, which is the one place it must not be.
 */
export function buildBodyWithSignature(signatureHtml: string): string {
    return `<p><br></p>${wrapBlock(signatureHtml)}`;
}

/**
 * Swap the signature block and leave everything around it untouched.
 *
 * If there is none, the new signature is appended — behind a cursor paragraph,
 * unless the body already ends in one.
 */
export function swapSignature(body: string, newSignatureHtml: string): string {
    const cleaned = body.replace(LEGACY_SIGNATURE, '');
    const { doc, container } = parseBody(cleaned);
    const existing = container.querySelector('[data-signature]');

    const wrapper = doc.createElement('div');
    wrapper.setAttribute('data-signature', '1');
    wrapper.innerHTML = newSignatureHtml;

    if (existing) {
        existing.replaceWith(wrapper);

        return container.innerHTML;
    }

    if (container.innerHTML === '') {
        return wrapper.outerHTML;
    }

    const lastChild = container.lastElementChild;

    if (!lastChild || lastChild.tagName !== 'P') {
        const spacer = doc.createElement('p');
        spacer.innerHTML = '<br>';
        container.appendChild(spacer);
    }

    container.appendChild(wrapper);

    return container.innerHTML;
}

/** Remove the signature block entirely — new and legacy form. */
export function stripSignature(body: string): string {
    const cleaned = body.replace(LEGACY_SIGNATURE, '');
    const { container } = parseBody(cleaned);

    container.querySelectorAll('[data-signature]').forEach((el) => el.remove());

    return container.innerHTML;
}
