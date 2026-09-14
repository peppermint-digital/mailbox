import { describe, expect, it } from 'vitest';
import { replyCcRecipients, replyToRecipients } from '../src/replyRecipients';

/**
 * Who a reply goes to, lifted from peppermint-manager (#5488).
 *
 * Worth testing because the failure is silent and public: a reply that goes to
 * more people than the writer expected.
 */
const original = {
    from_address: 'kunde@example.test',
    from_name: 'Kunde',
    to_addresses: [
        { email: 'info@crewtex.test', name: 'Crewtex' },
        { email: 'kollege@example.test', name: 'Kollege' },
    ],
    cc_addresses: [
        { email: 'chef@example.test', name: 'Chef' },
        { email: 'info@crewtex.test', name: 'Crewtex' },
    ],
    own_address: 'info@crewtex.test',
};

describe('replyToRecipients', () => {
    it('answers the sender alone by default', () => {
        expect(replyToRecipients(original, false)).toEqual([{ email: 'kunde@example.test', name: 'Kunde' }]);
    });

    it('adds the other recipients when replying to all', () => {
        expect(replyToRecipients(original, true).map((r) => r.email)).toEqual([
            'kunde@example.test',
            'kollege@example.test',
        ]);
    });

    it('never writes back to the own mailbox', () => {
        // Otherwise every reply-all mails the account back to itself — and in a
        // shared mailbox everyone reads their own reply as new mail.
        expect(replyToRecipients(original, true).map((r) => r.email)).not.toContain('info@crewtex.test');
    });

    it('ignores the case of an address', () => {
        const geschrien = { ...original, to_addresses: [{ email: 'INFO@CREWTEX.TEST', name: '' }] };

        expect(replyToRecipients(geschrien, true)).toHaveLength(1);
    });

    it('does not list anybody twice', () => {
        const doppelt = { ...original, to_addresses: [{ email: 'kunde@example.test', name: 'Kunde' }] };

        expect(replyToRecipients(doppelt, true)).toHaveLength(1);
    });

    it('survives a mail without any recipients', () => {
        expect(replyToRecipients({ ...original, to_addresses: null }, true)).toHaveLength(1);
    });
});

describe('replyCcRecipients', () => {
    it('stays empty unless replying to all', () => {
        expect(replyCcRecipients(original, false)).toEqual([]);
    });

    it('keeps the copies, minus the own mailbox and anybody already in To', () => {
        expect(replyCcRecipients(original, true).map((r) => r.email)).toEqual(['chef@example.test']);
    });

    it('drops somebody who moved into To', () => {
        const quelle = { ...original, cc_addresses: [{ email: 'kollege@example.test', name: 'Kollege' }] };

        expect(replyCcRecipients(quelle, true)).toEqual([]);
    });
});
