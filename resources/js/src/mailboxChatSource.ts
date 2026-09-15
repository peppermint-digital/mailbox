import type { MailboxChatSource, MailboxChatState } from './MailboxChat';

/**
 * The standard way to reach the mailbox agent over HTTP.
 *
 * Unlike folders and messages, the chat has exactly one answer shape — the Brain
 * defines it. Every product would write the same fetch, and the two places where
 * it can be written wrongly are not obvious:
 *
 * - A read that fails must NOT clear the conversation. The polling runs every
 *   few seconds; one dropped request while a lift passes would otherwise blank
 *   the whole thread. Returning null and keeping what is on screen is the reason
 *   `load` may answer null at all.
 * - A failed send has to carry the server's reason through. "Sending failed"
 *   with the reason thrown away is how a bug survives a week.
 */

export interface HttpChatSourceOptions {
    /** Where the conversation lives, e.g. `(id) => \`/mailbox/${id}/ai-chat\``. */
    url: (accountId: number) => string;
    /** Read it wherever your product keeps it — meta tag, cookie, anywhere. */
    csrfToken: string;
    /** Injectable for tests and for products with their own client. */
    fetch?: typeof globalThis.fetch;
}

export function httpMailboxChatSource({ url, csrfToken, fetch: injected }: HttpChatSourceOptions): MailboxChatSource {
    const doFetch = injected ?? ((...args: Parameters<typeof globalThis.fetch>) => globalThis.fetch(...args));

    return {
        async load(accountId: number, signal?: AbortSignal): Promise<MailboxChatState | null> {
            try {
                const antwort = await doFetch(url(accountId), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal,
                });

                if (!antwort.ok) {
                    return null;
                }

                const d = (await antwort.json()) as Partial<MailboxChatState>;

                return {
                    messages: d.messages ?? [],
                    status: d.status ?? null,
                    activity: d.activity ?? [],
                };
            } catch {
                // Offline, or the request was aborted on unmount. Keeping what is
                // on screen is right in both cases.
                return null;
            }
        },

        async send(accountId: number, content: string): Promise<string | null> {
            try {
                const antwort = await doFetch(url(accountId), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ content }),
                });

                if (antwort.ok) {
                    return null;
                }

                const d = (await antwort.json().catch(() => null)) as { error?: string } | null;

                return d?.error ? `Senden fehlgeschlagen: ${d.error}` : 'Senden fehlgeschlagen.';
            } catch {
                return 'Senden fehlgeschlagen.';
            }
        },
    };
}
