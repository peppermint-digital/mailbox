import { describe, expect, it, vi } from 'vitest';
import MailMessageView, { type MailMessageViewLabels, type MailMessageViewMessage } from '../src/MailMessageView';
import { click, render, textOf } from './support/render';

const labels: MailMessageViewLabels = {
    empty: 'Wählen Sie eine E-Mail aus',
    archived: 'Archiv',
    archivedTitle: 'Liegt nicht mehr im Postfach',
    editDraft: 'Weiter bearbeiten',
    reply: 'Antworten',
    replyAll: 'Allen antworten',
    forward: 'Weiterleiten',
    markRead: 'Als gelesen markieren',
    markUnread: 'Als ungelesen markieren',
    flag: 'Markieren',
    unflag: 'Markierung entfernen',
    archive: 'Archivieren',
    archiveConversation: (n) => `Ganze Konversation archivieren (${n} Nachrichten)`,
    delete: 'Löschen',
    from: 'Von:',
    date: 'Datum:',
    to: 'An:',
    cc: 'CC:',
    downloadAttachment: (f) => `${f} herunterladen`,
    body: { empty: 'Kein Inhalt', remoteBlocked: 'Blockiert', loadImages: 'Bilder laden', frameTitle: 'Inhalt' },
};

function message(overrides: Partial<MailMessageViewMessage> = {}): MailMessageViewMessage {
    return {
        uid: 1, message_id: '<a@b>', subject: 'Betreff', from_address: 'a@b.de', from_name: 'Absender',
        to: [{ email: 'ich@hier.de', name: 'Ich' }], cc: [], date: '2026-09-14T10:00:00Z',
        body_html: null, body_text: 'Hallo', attachments: [], is_read: true, is_flagged: false, ...overrides,
    };
}

const base = {
    labels,
    formatDateTime: (iso: string) => iso,
    formatAddressList: (a: { email: string; name: string }[]) => a.map((x) => x.email).join(', '),
    formatFileSize: (b: number) => `${b} B`,
    attachmentUrl: (a: { index: number }) => `/anhang/${a.index}`,
};

describe('MailMessageView', () => {
    it('says nothing is picked when nothing is', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={null} />);

        expect(textOf(container)).toContain('Wählen Sie eine E-Mail aus');
        unmount();
    });

    it('shows a spinner while fetching, not the empty state', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={null} loading />);

        expect(container.querySelector('[data-slot="mail-message-loading"]')).not.toBeNull();
        expect(textOf(container)).not.toContain('Wählen Sie eine E-Mail aus');
        unmount();
    });

    it('shows subject, sender and recipients', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={message()} />);

        expect(textOf(container)).toContain('Betreff');
        expect(textOf(container)).toContain('Absender');
        expect(textOf(container)).toContain('ich@hier.de');
        unmount();
    });

    describe('reply-all appears only when there is an "all"', () => {
        it('stays away for a single recipient without CC', () => {
            const { container, unmount } = render(<MailMessageView {...base} message={message()} />);

            expect(container.querySelector('[title="Allen antworten"]')).toBeNull();
            unmount();
        });

        it('appears with a second recipient', () => {
            const two = message({ to: [{ email: 'a@x.de', name: '' }, { email: 'b@x.de', name: '' }] });
            const { container, unmount } = render(<MailMessageView {...base} message={two} onReply={() => {}} />);

            expect(container.querySelector('[title="Allen antworten"]')).not.toBeNull();
            unmount();
        });

        it('appears with a CC', () => {
            const cc = message({ cc: [{ email: 'c@x.de', name: '' }] });
            const { container, unmount } = render(<MailMessageView {...base} message={cc} onReply={() => {}} />);

            expect(container.querySelector('[title="Allen antworten"]')).not.toBeNull();
            unmount();
        });
    });

    describe('an archived message has no toolbar', () => {
        const archived = message({ uid: null, source: 'stored' });

        it('shows the badge instead', () => {
            const { container, unmount } = render(<MailMessageView {...base} message={archived} />);

            expect(container.querySelector('[data-slot="mail-message-archived"]')).not.toBeNull();
            expect(container.querySelector('[data-slot="mail-message-actions"]')).toBeNull();
            unmount();
        });

        it('offers no reply — there is no uid to reply from', () => {
            const { container, unmount } = render(<MailMessageView {...base} message={archived} />);

            expect(container.querySelector('[title="Antworten"]')).toBeNull();
            unmount();
        });

        it('still shows the message itself', () => {
            const { container, unmount } = render(<MailMessageView {...base} message={archived} />);

            expect(textOf(container)).toContain('Betreff');
            unmount();
        });
    });

    it('offers "continue editing" only for a draft', () => {
        const plain = render(<MailMessageView {...base} message={message()} />);
        expect(plain.container.querySelector('[title="Weiter bearbeiten"]')).toBeNull();
        plain.unmount();

        const draft = render(<MailMessageView {...base} message={message()} isDraft onEditDraft={() => {}} />);
        expect(draft.container.querySelector('[title="Weiter bearbeiten"]')).not.toBeNull();
        draft.unmount();
    });

    it('says how many messages archiving moves', () => {
        const one = render(<MailMessageView {...base} message={message()} archiveCount={1} />);
        expect(one.container.querySelector('[title="Archivieren"]')).not.toBeNull();
        one.unmount();

        const many = render(<MailMessageView {...base} message={message()} archiveCount={4} />);
        expect(many.container.querySelector('[title="Ganze Konversation archivieren (4 Nachrichten)"]')).not.toBeNull();
        expect(textOf(many.container)).toContain('4');
        many.unmount();
    });

    it('names the state the read button will move to', () => {
        const read = render(<MailMessageView {...base} message={message({ is_read: true })} />);
        expect(read.container.querySelector('[title="Als ungelesen markieren"]')).not.toBeNull();
        read.unmount();

        const unread = render(<MailMessageView {...base} message={message({ is_read: false })} />);
        expect(unread.container.querySelector('[title="Als gelesen markieren"]')).not.toBeNull();
        unread.unmount();
    });

    it('replies, and says whether it means everyone', () => {
        const onReply = vi.fn();
        const two = message({ cc: [{ email: 'c@x.de', name: '' }] });
        const { container, unmount } = render(<MailMessageView {...base} message={two} onReply={onReply} />);

        click(container.querySelector('[title="Antworten"]') as HTMLButtonElement);
        expect(onReply).toHaveBeenLastCalledWith(false);

        click(container.querySelector('[title="Allen antworten"]') as HTMLButtonElement);
        expect(onReply).toHaveBeenLastCalledWith(true);
        unmount();
    });

    it('lists attachments with their size and a download link', () => {
        const withFile = message({ attachments: [{ index: 0, filename: 'Angebot.pdf', mime_type: 'application/pdf', size: 2048 }] });
        const { container, unmount } = render(<MailMessageView {...base} message={withFile} />);

        const link = container.querySelector('[data-slot="mail-message-attachments"] a') as HTMLAnchorElement;
        expect(link.getAttribute('href')).toBe('/anhang/0');
        expect(textOf(container)).toContain('Angebot.pdf');
        expect(textOf(container)).toContain('2048 B');
        unmount();
    });

    it('shows no attachment strip when there are none', () => {
        const { container, unmount } = render(<MailMessageView {...base} message={message()} />);

        expect(container.querySelector('[data-slot="mail-message-attachments"]')).toBeNull();
        unmount();
    });

    it('takes the product’s own badge and buttons', () => {
        const { container, unmount } = render(
            <MailMessageView
                {...base}
                message={message()}
                headerAccessory={<span>Zugewiesen an Bastian</span>}
                extraActions={<button type="button">Aufgabe zuordnen</button>}
            />,
        );

        expect(textOf(container)).toContain('Zugewiesen an Bastian');
        expect(textOf(container)).toContain('Aufgabe zuordnen');
        unmount();
    });

    it('zeigt Antworten und Weiterleiten nur mit Rueckruf', () => {
        // Bis zum 22.09.2026 standen sie immer da — auch in Produkten ohne
        // Sendeweg, wo sie beim Klick nichts taten. Ein Knopf ohne Wirkung
        // sieht aus wie ein Defekt, nicht wie eine fehlende Funktion.
        const { container, rerender, unmount } = render(<MailMessageView {...base} message={message()} />);

        expect(container.querySelector('button[title="Antworten"]')).toBeNull();
        expect(container.querySelector('button[title="Weiterleiten"]')).toBeNull();

        rerender(<MailMessageView {...base} message={message()} onReply={() => {}} onForward={() => {}} />);

        expect(container.querySelector('button[title="Antworten"]')).not.toBeNull();
        expect(container.querySelector('button[title="Weiterleiten"]')).not.toBeNull();
        unmount();
    });
});
