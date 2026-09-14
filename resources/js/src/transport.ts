/**
 * Talking to a mailbox endpoint.
 *
 * The addresses belong to the product; what happens around a request does not.
 * Every product would write the same headers, the same credentials handling,
 * and — the part that actually matters — the same judgement about what counts
 * as success.
 *
 * ## "HTTP 200" is not an answer for a bulk action
 *
 * A bulk endpoint answers 200 with `success: true` even when it caught no
 * message at all. Until 13.09.2026 the mail browser ended there: someone could
 * tick five mails, hit delete, and see neither an error nor an effect. The
 * count has to be read and judged, not the status code.
 *
 * ## The CSRF token comes in
 *
 * Where it is kept is a product decision — a meta tag in one app, a cookie in
 * the next. The package asks for the value, not for the place.
 */

export interface MailRequestOptions {
    /** The CSRF token to send. Read it wherever your product keeps it. */
    csrfToken: string;
    /** Injectable for tests and for products with their own client. */
    fetch?: typeof globalThis.fetch;
    signal?: AbortSignal;
}

/** What a bulk endpoint reports back about its own work. */
export interface BulkOutcome {
    processed: number;
    failed: number;
}

function headersFor(csrfToken: string): Record<string, string> {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken,
    };
}

/**
 * A request whose only question is "did it work".
 *
 * Returns false rather than throwing: a failed mailbox action is an expected
 * outcome, not an exception, and every caller would otherwise wrap it.
 */
export async function postJson(url: string, body: Record<string, unknown>, options: MailRequestOptions): Promise<boolean> {
    const doFetch = options.fetch ?? globalThis.fetch;

    try {
        const response = await doFetch(url, {
            method: 'POST',
            headers: headersFor(options.csrfToken),
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal: options.signal,
        });

        return response.ok;
    } catch {
        return false;
    }
}

/**
 * A request that reports how much it actually did.
 *
 * Returns null when the answer cannot be trusted — a non-OK status, a body
 * that is not JSON (a web server's own 413 page, for instance), or
 * `success: false`. A caller that gets null knows nothing happened; a caller
 * that gets `{processed: 0}` knows the endpoint ran and caught nothing. Those
 * are different, and collapsing them is how "no error, no effect" happens.
 */
export async function postCounted(
    url: string,
    body: Record<string, unknown>,
    options: MailRequestOptions,
): Promise<BulkOutcome | null> {
    const doFetch = options.fetch ?? globalThis.fetch;

    let response: Response;

    try {
        response = await doFetch(url, {
            method: 'POST',
            headers: headersFor(options.csrfToken),
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal: options.signal,
        });
    } catch {
        return null;
    }

    if (!response.ok) {
        return null;
    }

    try {
        const data = (await response.json()) as { success?: boolean; processed?: number; failed?: number };

        if (!data.success) {
            return null;
        }

        return { processed: data.processed ?? 0, failed: data.failed ?? 0 };
    } catch {
        return null;
    }
}

/**
 * Did a bulk action do anything at all?
 *
 * The question every caller asks after `postCounted`, spelled once so nobody
 * writes `result && result.processed > 0` slightly differently the fourth time.
 */
export function didSomething(outcome: BulkOutcome | null): boolean {
    return outcome !== null && outcome.processed > 0;
}
