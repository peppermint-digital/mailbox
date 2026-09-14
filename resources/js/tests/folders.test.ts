import { describe, expect, it } from 'vitest';
import { classifyFolder, isProtectedFolder, looksLikeFolder } from '../src/folders';

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
