import { describe, expect, it } from 'vitest';
import { classifyFolder, isProtectedFolder, looksLikeFolder, ordnerAnzeigename } from '../src/folders';

describe('isProtectedFolder', () => {
    it('protects INBOX whatever its flags say', () => {
        expect(isProtectedFolder({ path: 'INBOX' })).toBe(true);
        expect(isProtectedFolder({ path: 'inbox' })).toBe(true);
    });

    it('protects what the server marks as special', () => {
        expect(isProtectedFolder({ path: 'Entw&APw-rfe', flags: ['\\Drafts'] })).toBe(true);
    });

    it('leaves an ordinary folder alone', () => {
        expect(isProtectedFolder({ path: 'Projekte/2026', flags: [] })).toBe(false);
    });
});

describe('looksLikeFolder', () => {
    it('believes the server flag over everything else', () => {
        // A folder named nothing like drafts, but marked as drafts.
        expect(looksLikeFolder({ path: 'Kladde', flags: ['\\Drafts'] }, 'Drafts')).toBe(true);
    });

    it('recognises the IMAP UTF-7 spelling Office 365 reports', () => {
        // This is the case a hand-written /drafts|entwürf/ pattern misses.
        expect(looksLikeFolder({ path: 'Entw&APw-rfe' }, 'Drafts')).toBe(true);
        expect(looksLikeFolder({ path: 'Gel&APY-schte Elemente' }, 'Trash')).toBe(true);
    });

    it('recognises the plain German and English names', () => {
        expect(looksLikeFolder({ path: 'Entwürfe' }, 'Drafts')).toBe(true);
        expect(looksLikeFolder({ path: 'INBOX.Drafts' }, 'Drafts')).toBe(true);
        expect(looksLikeFolder({ path: 'Gesendete Elemente' }, 'Sent')).toBe(true);
        expect(looksLikeFolder({ path: '[Gmail]/Papierkorb' }, 'Trash')).toBe(true);
    });

    it('falls back to the display name when the path says nothing', () => {
        expect(looksLikeFolder({ path: 'INBOX.X1', name: 'Entwürfe' }, 'Drafts')).toBe(true);
    });

    it('does not mistake one standard folder for another', () => {
        expect(looksLikeFolder({ path: 'Gesendete Elemente' }, 'Drafts')).toBe(false);
        expect(looksLikeFolder({ path: 'Entwürfe' }, 'Trash')).toBe(false);
    });

    it('says no for an ordinary folder', () => {
        expect(looksLikeFolder({ path: 'Projekte/2026', flags: [] }, 'Drafts')).toBe(false);
    });
});

describe('classifyFolder', () => {
    it('names the standard folder behind a path', () => {
        expect(classifyFolder({ path: 'Entw&APw-rfe' })).toBe('Drafts');
        expect(classifyFolder({ path: 'INBOX.Sent' })).toBe('Sent');
        expect(classifyFolder({ path: 'Papierkorb' })).toBe('Trash');
        expect(classifyFolder({ path: 'Archiv' })).toBe('Archive');
    });

    it('is null for an ordinary folder', () => {
        expect(classifyFolder({ path: 'Projekte/2026', flags: [] })).toBeNull();
    });
});

describe('ordnerAnzeigename', () => {
    /*
     * Gemeldet am 30.10.2026 (#9134): Die Verwaltung zeigte auf dem Telefon
     * „INBOX". Derselbe Ordner heisst je nach Server anders — und damit hiess
     * dasselbe Postfach in zwei Produkten verschieden.
     */
    it('uebersetzt die Standardordner, egal wie der Server sie nennt', () => {
        expect(ordnerAnzeigename({ path: 'INBOX' })).toBe('Posteingang');
        expect(ordnerAnzeigename({ path: 'INBOX.Sent' })).toBe('Gesendet');
        expect(ordnerAnzeigename({ path: 'Sent Items', name: 'Sent Items' })).toBe('Gesendet');
        expect(ordnerAnzeigename({ path: 'Entw&APw-rfe', name: 'Entw&APw-rfe' })).toBe('Entwürfe');
        expect(ordnerAnzeigename({ path: 'Gelöschte Elemente' })).toBe('Papierkorb');
        expect(ordnerAnzeigename({ path: 'Junk E-Mail' })).toBe('Spam');
        expect(ordnerAnzeigename({ path: 'Archive' })).toBe('Archiv');
    });

    it('glaubt der Flagge des Servers vor dem Namen', () => {
        // Ein Postfach, dessen Entwurfsordner „Konzepte" heisst — der Server
        // sagt, was er ist, und das schlaegt jede Namenstabelle.
        expect(ordnerAnzeigename({ path: 'Konzepte', name: 'Konzepte', flags: ['\\Drafts'] })).toBe('Entwürfe');
    });

    it('laesst eigene Ordner in Ruhe — auch die, die fast so heissen', () => {
        /*
         * Der Unterschied zu `looksLikeFolder`, und der Grund fuer die zweite
         * Tabelle: Dort ist ein Teiltreffer gewollt (ein verpasster
         * Entwurfsordner ist teurer als einer zu viel). Hier waere er ein
         * Fehler — ein Ordner heisst, wie der Mensch ihn genannt hat.
         */
        expect(ordnerAnzeigename({ path: 'Archiv 2024', name: 'Archiv 2024' })).toBe('Archiv 2024');
        expect(ordnerAnzeigename({ path: 'INBOX.Kunden', name: 'Kunden' })).toBe('Kunden');
        expect(ordnerAnzeigename({ path: 'Projekte/2026', name: 'Projekte/2026' })).toBe('Projekte/2026');
    });

    it('nimmt den Pfad, wenn kein Name mitkommt', () => {
        // Beim ersten Zeichnen ist die Ordnerliste noch nicht da; genau dann
        // stand „INBOX" in der schmalen Leiste.
        expect(ordnerAnzeigename({ path: 'INBOX' })).toBe('Posteingang');
        expect(ordnerAnzeigename({ path: 'Eigenes' })).toBe('Eigenes');
    });
});
