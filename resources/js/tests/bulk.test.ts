import { describe, expect, it } from 'vitest';
import { groupUidsByFolder, runBulkAcrossFolders } from '../src/bulk';
import type { RunBulkOptions } from '../src/bulk';
import { postCounted } from '../src/transport';

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

/**
 * Die Naht zwischen Server und Transport (gefunden am 20.10.2026).
 *
 * Diese Tests sind die einzigen hier, die NICHT `send` erfinden, sondern das
 * echte `postCounted` mit genau dem Rumpf fuettern, den `bulk()` im Paket
 * schickt. Der Grund ist ein Fehler, den beide Haelften fuer sich gruen
 * ueberstanden haben:
 *
 *   `bulk()` gab bei einem Teilerfolg `success: false` zurueck,
 *   `postCounted()` macht daraus `null`,
 *   `runBulkAcrossFolders()` macht daraus `unreachable`,
 *   und die Oberflaeche sagt „Sammelaktion fehlgeschlagen".
 *
 * Die Zweige „3 von 5 bearbeitet" und „keine einzige, laden Sie die Liste neu"
 * waren damit unerreichbar — die Meldungen, die der Fund vom 13.09.2026 erst
 * eingefuehrt hatte. Jede Haelfte hatte einen Test, der ihr Verhalten
 * festschrieb. Gefehlt hat der, der sie aneinanderhaelt.
 */
describe('Naht: der Rumpf des Servers durch den echten Transport', () => {
    /** Was `HandlesMailboxActions::bulk()` schickt — Schluessel und Werte wie dort. */
    function serverAntwort(processed: number, failed: number): Response {
        return {
            ok: true,
            status: 200,
            json: async () => ({ success: true, processed, failed }),
        } as unknown as Response;
    }

    function sendeUeber(antworten: Response[]): RunBulkOptions['send'] {
        let i = 0;
        return (_ordner, uids) =>
            postCounted('/mail/bulk', { uids }, {
                csrfToken: 'tok',
                fetch: (async () => antworten[i++]) as never,
            });
    }

    it('nennt einen Teilerfolg einen Teilerfolg', async () => {
        const verdikt = await runBulkAcrossFolders({
            uids: [1, 2, 3, 4, 5],
            folderOf: () => 'INBOX',
            currentFolder: 'INBOX',
            send: sendeUeber([serverAntwort(3, 2)]),
        });

        expect(verdikt.kind).toBe('partial');
        expect(verdikt.processed).toBe(3);
        expect(verdikt.failed).toBe(2);
    });

    it('unterscheidet „keine einzige erwischt" von „nicht erreichbar"', async () => {
        // Der Unterschied ist die naechste Handlung des Menschen davor:
        // abwarten oder die Liste neu laden.
        const verdikt = await runBulkAcrossFolders({
            uids: [1, 2],
            folderOf: () => 'INBOX',
            currentFolder: 'INBOX',
            send: sendeUeber([serverAntwort(0, 2)]),
        });

        expect(verdikt.kind).toBe('none');
        expect(verdikt.failed).toBe(2);
    });

    it('laesst einen teilweisen Ordner nicht den gelungenen mitnehmen', async () => {
        /*
         * `unreachable` klebt: Es gewinnt im Verdikt ueber alles andere. Kam
         * fuer einen Ordner ein `null`, hiess das Ergebnis „fehlgeschlagen",
         * auch wenn im zweiten Ordner zwanzig Mails verschoben wurden.
         *
         * Zwei Ordner sind der Normalfall, nicht der Rand — eine Sammelaktion
         * auf Suchtreffer greift fast immer in mehrere.
         */
        const verdikt = await runBulkAcrossFolders({
            uids: [1, 2, 3],
            folderOf: (uid) => (uid === 3 ? 'Archiv' : 'INBOX'),
            currentFolder: 'INBOX',
            send: sendeUeber([serverAntwort(1, 1), serverAntwort(1, 0)]),
        });

        expect(verdikt.kind).toBe('partial');
        expect(verdikt.processed).toBe(2);
        expect(verdikt.failed).toBe(1);
    });

    it('bleibt bei „nicht erreichbar", wenn der Server wirklich nicht antwortet', async () => {
        // Die Gegenprobe: Der Zweig muss erhalten bleiben. Sonst waere die
        // Reparatur nur eine Verschiebung des Problems.
        const verdikt = await runBulkAcrossFolders({
            uids: [1],
            folderOf: () => 'INBOX',
            currentFolder: 'INBOX',
            send: () => Promise.resolve(null),
        });

        expect(verdikt.kind).toBe('unreachable');
    });
});
