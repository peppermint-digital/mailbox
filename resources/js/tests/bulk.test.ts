import { describe, expect, it } from 'vitest';
import { groupUidsByFolder } from '../src/bulk';

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
