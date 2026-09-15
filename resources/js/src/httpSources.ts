import type { MailFolder } from './MailFolderList';
import type { FolderSource } from './useMailboxFolders';
import type { RowMessage } from './rows';
import type { ListParams, MailboxListSource, MessagePage } from './useMailboxList';
import type { OpenMessageSource, OpenedMessage } from './useOpenMessage';

/**
 * The standard way to reach a mailbox over HTTP.
 *
 * ## Why this exists
 *
 * The three sources are contracts — a product could answer them from anywhere.
 * In practice both products that exist answered them the same way, character for
 * character, and differed in exactly one thing: the address.
 *
 * That was not a coincidence waiting to be discovered but a prediction written
 * down in the Manager's own comment: *"die Adresse ist das Einzige, worin sich
 * Produkte an dieser Stelle unterscheiden."* Leaving the rest to each product
 * means every one of them re-decides the same three questions:
 *
 * - **What counts as a refusal?** `daten.success === false` is an answer from the
 *   server, with a reason. A thrown fetch is not — nobody answered at all. The
 *   hooks keep those apart, and a product that collapses them shows "not
 *   reachable" for a mailbox that clearly said why it refused.
 * - **What is missing worth?** `daten.messages ?? []` — an absent key means an
 *   empty page, not a broken one. Without the fallback the list renders
 *   `undefined.length` and takes the screen with it.
 * - **Which failures may pass silently?** `listTargets` answers `[]` on any
 *   problem: it fills a "move to…" menu, and a menu that is short is better than
 *   a mail browser that is gone.
 *
 * ## What stays with the product
 *
 * The addresses, and only those. Every function below receives what it needs to
 * build a URL and returns one — including query parameters the product invented
 * for itself (`?refresh=1`, `threads` vs `messages`). The package never guesses
 * a route.
 */

/** Where this product keeps each of its mailbox endpoints. */
export interface MailboxRoutes {
    folders(accountId: number | string, params: { refresh: boolean }): string;
    /** Every folder a message may be moved into. Omit if the product has no such route. */
    targets?(accountId: number | string): string;
    messages(accountId: number | string, params: Omit<ListParams, 'accountId'>): string;
    message(accountId: number | string, params: { uid: number; folder: string }): string;
}

export interface HttpMailboxSourcesOptions {
    routes: MailboxRoutes;
    /** The account being viewed. A function, because it changes as the user switches. */
    accountId: () => number | string;
    /** Injectable for tests and for products with their own client. */
    fetch?: typeof globalThis.fetch;
}

const KOPFZEILEN = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

export interface HttpMailboxSources<M extends RowMessage, D> {
    folders: FolderSource;
    messages: MailboxListSource<M>;
    message: OpenMessageSource<M, D>;
}

export function httpMailboxSources<M extends RowMessage, D>({
    routes,
    accountId,
    fetch: injected,
}: HttpMailboxSourcesOptions): HttpMailboxSources<M, D> {
    const doFetch = injected ?? ((...args: Parameters<typeof globalThis.fetch>) => globalThis.fetch(...args));

    /**
     * One request, and the judgement every caller would otherwise repeat.
     *
     * Deliberately NOT catching: a thrown fetch has to stay thrown. The hooks
     * tell "the server refused" from "nobody answered" by exactly that, and the
     * two need different words on screen.
     */
    async function frage(url: string): Promise<Record<string, unknown>> {
        const antwort = await doFetch(url, { headers: KOPFZEILEN, credentials: 'same-origin' });

        return (await antwort.json()) as Record<string, unknown>;
    }

    return {
        folders: {
            async list({ accountId: id, refresh }) {
                const daten = await frage(routes.folders(id, { refresh }));

                return daten.success ? ((daten.folders ?? []) as MailFolder[]) : { failure: daten.message as string | undefined };
            },
            ...(routes.targets
                ? {
                      async listTargets({ accountId: id }: { accountId: number | string }) {
                          // Any problem answers []: this fills a "move to…" menu,
                          // and a short menu beats a mail browser that is gone.
                          try {
                              const daten = await frage(routes.targets!(id));

                              return (daten.folders ?? []) as MailFolder[];
                          } catch {
                              return [];
                          }
                      },
                  }
                : {}),
        },

        messages: {
            async list(params: ListParams) {
                const { accountId: id, ...rest } = params;
                const daten = await frage(routes.messages(id, rest));

                if (!daten.success) {
                    return { failure: daten.message as string | undefined };
                }

                return {
                    messages: (daten.messages ?? []) as M[],
                    threads: (daten.threads ?? []) as MessagePage<M>['threads'],
                    total: (daten.total ?? 0) as number,
                } satisfies MessagePage<M>;
            },
        },

        message: {
            async open({ message, folder }) {
                const daten = await frage(routes.message(accountId(), { uid: message.uid, folder }));

                if (!daten.success) {
                    return { failure: daten.message as string | undefined };
                }

                return {
                    message: daten.message as D,
                    // The answer may name a different folder than the caller
                    // believed — a message that has been moved is still findable,
                    // and the caller has to learn where it went.
                    folder: (daten.folder ?? null) as string | null,
                } as OpenedMessage<D>;
            },
        },
    };
}
