import { describe, expect, it } from 'vitest';
import { formatAddress, formatAddressList, formatSender } from '../src/addresses';
import { isProtectedFolder } from '../src/folders';

describe('formatAddress', () => {
    it('shows the name with the address behind it', () => {
        expect(formatAddress({ email: 'a@x', name: 'Anna' })).toBe('Anna <a@x>');
    });

    it('falls back to the bare address', () => {
        expect(formatAddress({ email: 'a@x' })).toBe('a@x');
        expect(formatAddress({ email: 'a@x', name: '' })).toBe('a@x');
    });

    it('joins a list for a header line', () => {
        expect(formatAddressList([{ email: 'a@x', name: 'Anna' }, { email: 'b@x' }])).toBe('Anna <a@x>, b@x');
    });
});

describe('formatSender', () => {
    it('prefers the name, then the address, then the product word', () => {
        expect(formatSender({ from_name: 'Anna', from_address: 'a@x' }, 'Unbekannt')).toBe('Anna');
        expect(formatSender({ from_address: 'a@x' }, 'Unbekannt')).toBe('a@x');
        expect(formatSender({}, 'Unbekannt')).toBe('Unbekannt');
    });
});

describe('isProtectedFolder', () => {
    it('never lets go of INBOX', () => {
        // The one folder every mailbox has; losing it loses the mailbox.
        expect(isProtectedFolder({ path: 'INBOX' })).toBe(true);
        expect(isProtectedFolder({ path: 'inbox' })).toBe(true);
    });

    it('trusts the server marks over the name', () => {
        expect(isProtectedFolder({ path: 'Irgendwas', flags: ['\\Trash'] })).toBe(true);
        expect(isProtectedFolder({ path: 'Papierkorb', flags: [] })).toBe(false);
    });

    it('leaves an ordinary folder alone', () => {
        expect(isProtectedFolder({ path: 'Kunden/2026', flags: ['\\HasNoChildren'] })).toBe(false);
    });
});
