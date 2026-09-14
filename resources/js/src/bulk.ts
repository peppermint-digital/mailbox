import { didSomething, type BulkOutcome } from './transport';

/**
 * Acting on many messages at once.
 *
 * ## Why this has to be grouped
 *
 * A uid is **only unique inside its folder**. That is not a protocol detail
 * but the cause of a whole class of bugs: tick everything in a list of search
 * hits — which come from several folders — and delete, and foreign uids go to
 * one folder. The server then deletes nothing, or the wrong message.
 *
 * So every bulk action groups by folder first and makes one call per folder.
 */

/**
 * Splits uids by the folder they actually sit in.
 *
 * @param uids the ticked uids
 * @param folderOf says where a uid sits — unknown means the fallback
 * @param fallback the folder for everything `folderOf` does not know
 */
export function groupUidsByFolder(
    uids: Iterable<number>,
    folderOf: (uid: number) => string | null | undefined,
    fallback: string,
): Map<string, number[]> {
    const byFolder = new Map<string, number[]>();

    for (const uid of uids) {
        const folder = folderOf(uid) || fallback;

        byFolder.set(folder, [...(byFolder.get(folder) ?? []), uid]);
    }

    return byFolder;
}

/**
 * What a bulk action achieved, as something a product can render.
 *
 * Four outcomes, because collapsing them loses the one that matters:
 *
 * - `ok` — everything the action was asked to do happened
 * - `partial` — some messages were caught, some were not
 * - `none` — the endpoint ran and caught nothing. Usually the list is stale:
 *   the messages no longer sit where the browser thinks they do.
 * - `unreachable` — at least one call gave no trustworthy answer, so what
 *   happened is unknown. Reporting this as `none` would claim knowledge
 *   nobody has.
 */
export type BulkVerdictKind = 'ok' | 'partial' | 'none' | 'unreachable';

export interface BulkVerdict {
    kind: BulkVerdictKind;
    processed: number;
    failed: number;
    /** Folders that were skipped because the move would not have moved anything. */
    skipped: string[];
}

export interface RunBulkOptions {
    uids: Iterable<number>;
    /** Says where a uid sits; unknown falls back to `currentFolder`. */
    folderOf: (uid: number) => string | null | undefined;
    currentFolder: string;
    /** Set for a move; groups already in that folder are left out. */
    targetFolder?: string | null;
    /** Sends one folder's worth of work. Null means the answer cannot be trusted. */
    send: (folder: string, uids: number[]) => Promise<BulkOutcome | null>;
}

/**
 * Runs a bulk action across the folders its messages really sit in.
 *
 * Moving messages into the folder they already occupy is an error on the
 * server's side, so those groups are skipped rather than sent — and the
 * verdict names them, because "nothing happened" and "nothing needed to
 * happen" read the same to a user otherwise.
 */
export async function runBulkAcrossFolders(options: RunBulkOptions): Promise<BulkVerdict> {
    const { uids, folderOf, currentFolder, targetFolder, send } = options;

    const byFolder = groupUidsByFolder(uids, folderOf, currentFolder);

    let processed = 0;
    let failed = 0;
    let unreachable = false;
    const skipped: string[] = [];

    for (const [folder, folderUids] of byFolder) {
        if (targetFolder && folder === targetFolder) {
            skipped.push(folder);
            continue;
        }

        const outcome = await send(folder, folderUids);

        if (!outcome) {
            unreachable = true;
            continue;
        }

        processed += outcome.processed;
        failed += outcome.failed;
    }

    if (unreachable) {
        return { kind: 'unreachable', processed, failed, skipped };
    }

    if (failed > 0) {
        return { kind: didSomething({ processed, failed }) ? 'partial' : 'none', processed, failed, skipped };
    }

    return { kind: 'ok', processed, failed, skipped };
}
