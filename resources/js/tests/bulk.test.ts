import { describe, expect, it } from 'vitest';
import { groupUidsByFolder, runBulkAcrossFolders } from '../src/bulk';

/**
 * Aus peppermint-manager gehoben (#5488, dort Bug #587).
 *
 * Eine UID ist nur innerhalb ihres Ordners eindeutig. Wer das übersieht,
 * schickt bei einer Sammelaktion auf Suchtreffer fremde Kennungen an einen
 * Ordner — und der Server löscht nichts oder das Falsche.
 */
describe('groupUidsByFolder', () => {
    it('teilt Treffer aus verschiedenen Ordnern auf', () => {
        const ordner = { 1: 'INBOX', 2: 'Archiv', 3: 'INBOX' } as Record<number, string>;

        const gruppen = groupUidsByFolder([1, 2, 3], (uid) => ordner[uid], 'INBOX');

        expect([...gruppen.entries()]).toEqual([
            ['INBOX', [1, 3]],
            ['Archiv', [2]],
        ]);
    });

    it('nimmt den Standardordner, wo die Herkunft unbekannt ist', () => {
        // In der normalen Liste kennt der Aufrufer den Ordner gar nicht — dort
        // ist es der gerade geöffnete.
        const gruppen = groupUidsByFolder([7, 8], () => null, 'Gesendet');

        expect(gruppen.get('Gesendet')).toEqual([7, 8]);
    });

    it('behält die Reihenfolge innerhalb eines Ordners', () => {
        const gruppen = groupUidsByFolder([9, 4, 6], () => 'INBOX', 'INBOX');

        expect(gruppen.get('INBOX')).toEqual([9, 4, 6]);
    });

    it('kommt mit einer leeren Auswahl zurecht', () => {
        expect(groupUidsByFolder([], () => 'INBOX', 'INBOX').size).toBe(0);
    });
});

describe('runBulkAcrossFolders', () => {
    const treffer = (processed: number, failed = 0) => async () => ({ processed, failed });

    it('sends one call per folder the messages really sit in', async () => {
        const gesendet: Array<[string, number[]]> = [];
        const wo: Record<number, string> = { 1: 'INBOX', 2: 'Archiv', 3: 'INBOX' };

        await runBulkAcrossFolders({
            uids: [1, 2, 3],
            folderOf: (uid) => wo[uid],
            currentFolder: 'INBOX',
            send: async (folder, uids) => {
                gesendet.push([folder, uids]);
                return { processed: uids.length, failed: 0 };
            },
        });

        expect(gesendet).toEqual([['INBOX', [1, 3]], ['Archiv', [2]]]);
    });

    it('skips a move into the folder the messages already sit in', async () => {
        const gesendet: string[] = [];

        const verdikt = await runBulkAcrossFolders({
            uids: [1, 2],
            folderOf: (uid) => (uid === 1 ? 'Archiv' : 'INBOX'),
            currentFolder: 'INBOX',
            targetFolder: 'Archiv',
            send: async (folder, uids) => {
                gesendet.push(folder);
                return { processed: uids.length, failed: 0 };
            },
        });

        expect(gesendet).toEqual(['INBOX']);
        expect(verdikt.skipped).toEqual(['Archiv']);
    });

    it('says ok when everything was caught', async () => {
        const v = await runBulkAcrossFolders({
            uids: [1], folderOf: () => 'INBOX', currentFolder: 'INBOX', send: treffer(1),
        });

        expect(v).toMatchObject({ kind: 'ok', processed: 1, failed: 0 });
    });

    it('says partial when some were caught and some were not', async () => {
        const v = await runBulkAcrossFolders({
            uids: [1], folderOf: () => 'INBOX', currentFolder: 'INBOX', send: treffer(2, 3),
        });

        expect(v).toMatchObject({ kind: 'partial', processed: 2, failed: 3 });
    });

    it('says none when the endpoint ran and caught nothing', async () => {
        // The list is stale — the messages are not where the browser thinks.
        const v = await runBulkAcrossFolders({
            uids: [1], folderOf: () => 'INBOX', currentFolder: 'INBOX', send: treffer(0, 4),
        });

        expect(v).toMatchObject({ kind: 'none', processed: 0, failed: 4 });
    });

    it('says unreachable rather than none when an answer cannot be trusted', async () => {
        // Claiming "caught nothing" would assert knowledge nobody has.
        const v = await runBulkAcrossFolders({
            uids: [1], folderOf: () => 'INBOX', currentFolder: 'INBOX', send: async () => null,
        });

        expect(v.kind).toBe('unreachable');
    });

    it('stays unreachable even when another folder succeeded', async () => {
        const v = await runBulkAcrossFolders({
            uids: [1, 2],
            folderOf: (uid) => (uid === 1 ? 'INBOX' : 'Archiv'),
            currentFolder: 'INBOX',
            send: async (folder) => (folder === 'INBOX' ? { processed: 1, failed: 0 } : null),
        });

        expect(v).toMatchObject({ kind: 'unreachable', processed: 1 });
    });

    it('puts uids with an unknown folder into the current one', async () => {
        const gesendet: Array<[string, number[]]> = [];

        await runBulkAcrossFolders({
            uids: [9],
            folderOf: () => null,
            currentFolder: 'INBOX',
            send: async (folder, uids) => {
                gesendet.push([folder, uids]);
                return { processed: 1, failed: 0 };
            },
        });

        expect(gesendet).toEqual([['INBOX', [9]]]);
    });
});
