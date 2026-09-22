import { describe, expect, it, vi } from 'vitest';
import MailMessageList, { type MailMessageListLabels } from '../src/MailMessageList';
import type { DisplayRow, RowMessage } from '../src/rows';
import { click, render, textOf } from './support/render';

const labels: MailMessageListLabels = {
    unread: 'Ungelesen',
    outboundSender: 'Ihre Antwort',
    outboundBadge: 'gesendet',
    outboundBadgeTitle: 'Aus dem Postfach gesendet',
    expandThread: 'Konversation aufklappen',
    collapseThread: 'Konversation zuklappen',
    newest: 'Neueste',
    newestTitle: 'Die jüngste Nachricht dieser Konversation',
    unreadCount: (n) => `${n} neu`,
    unreadCountTitle: (n, total) => `${n} von ${total} ungelesen`,
    archived: 'Archiv',
    archivedTitle: 'Aus dem Archiv der Anwendung',
    foundInFolder: (f) => `Gefunden in ${f}`,
    flagged: 'Markiert',
    hasAttachments: 'Anhänge',
    selectMessage: (betreff) => `„${betreff}" auswählen`,
};

function msg(overrides: Partial<RowMessage> = {}): RowMessage {
    return {
        uid: 1, subject: 'Betreff', from_address: 'a@b.de', from_name: 'Absender',
        date: '2026-09-14T10:00:00Z', has_attachments: false, attachment_count: 0,
        is_read: true, is_flagged: false, preview: 'Vorschau', ...overrides,
    };
}

function row(overrides: Partial<DisplayRow<RowMessage>> = {}): DisplayRow<RowMessage> {
    return { msg: msg(), key: '', count: 1, isMember: false, ...overrides };
}

const base = {
    labels,
    formatDate: (iso: string | null) => iso ?? '-',
    formatSender: (m: RowMessage) => m.from_name,
    onOpen: () => {},
};

describe('MailMessageList', () => {
    it('names every checkbox by the message it selects', () => {
        // Without it, twenty rows carry twenty controls announced as "checkbox".
        const { container, unmount } = render(<MailMessageList {...base} rows={[row()]} />);

        expect(container.querySelector('[data-slot="checkbox"]')?.getAttribute('aria-label')).toBe('„Betreff" auswählen');
        unmount();
    });

    it('shows sender, subject and preview', () => {
        const { container, unmount } = render(<MailMessageList {...base} rows={[row()]} />);

        expect(textOf(container)).toContain('Absender');
        expect(textOf(container)).toContain('Betreff');
        unmount();
    });

    it('opens a message when its row is clicked', () => {
        const onOpen = vi.fn();
        const { container, unmount } = render(<MailMessageList {...base} onOpen={onOpen} rows={[row()]} />);

        click(container.querySelector('[data-slot="mail-message-open"]') as HTMLButtonElement);

        expect(onOpen).toHaveBeenCalledOnce();
        unmount();
    });

    describe('a stored reply is not a mailbox message', () => {
        const stored = row({ msg: msg({ uid: 0, source: 'stored', stored_id: 5 }), isOutbound: true, isMember: true, key: 't1' });

        it('replaces the sender with the product word', () => {
            const { container, unmount } = render(<MailMessageList {...base} rows={[stored]} />);

            expect(textOf(container)).toContain('Ihre Antwort');
            expect(textOf(container)).not.toContain('Absender');
            unmount();
        });

        it('offers no checkbox — there is no uid to act on', () => {
            const { container, unmount } = render(<MailMessageList {...base} rows={[stored]} />);

            expect(container.querySelector('[data-slot="checkbox"]')).toBeNull();
            unmount();
        });

        it('cannot be clicked open', () => {
            const onOpen = vi.fn();
            const { container, unmount } = render(<MailMessageList {...base} onOpen={onOpen} rows={[stored]} />);

            const button = container.querySelector('[data-slot="mail-message-open"]') as HTMLButtonElement;
            expect(button.disabled).toBe(true);

            click(button);
            expect(onOpen).not.toHaveBeenCalled();
            unmount();
        });
    });

    describe('conversations', () => {
        const head = row({ key: 't1', count: 3, unreadCount: 2, isNewest: true });

        it('counts the conversation and how much of it is new', () => {
            const { container, unmount } = render(<MailMessageList {...base} rows={[head]} />);

            expect(textOf(container)).toContain('3');
            expect(textOf(container)).toContain('2 neu');
            unmount();
        });

        it('marks the newest only while the conversation is expanded', () => {
            const collapsed = render(<MailMessageList {...base} rows={[head]} />);
            expect(textOf(collapsed.container)).not.toContain('Neueste');
            collapsed.unmount();

            const open = render(<MailMessageList {...base} rows={[head]} expandedThreads={new Set(['t1'])} />);
            expect(textOf(open.container)).toContain('Neueste');
            open.unmount();
        });

        it('keeps the unread badge off members — it belongs to the head', () => {
            const member = row({ key: 't1', count: 0, isMember: true, unreadCount: 2 });
            const { container, unmount } = render(<MailMessageList {...base} rows={[member]} />);

            expect(textOf(container)).not.toContain('2 neu');
            unmount();
        });

        it('toggles a conversation from its chevron', () => {
            const onToggleThread = vi.fn();
            const { container, unmount } = render(<MailMessageList {...base} rows={[head]} onToggleThread={onToggleThread} />);

            click(container.querySelector('button[title="Konversation aufklappen"]') as HTMLButtonElement);

            expect(onToggleThread).toHaveBeenCalledWith('t1');
            unmount();
        });

        it('shows no chevron on a single message', () => {
            const { container, unmount } = render(<MailMessageList {...base} rows={[row()]} />);

            expect(container.querySelector('button[title="Konversation aufklappen"]')).toBeNull();
            unmount();
        });
    });

    describe('search says where a hit sits', () => {
        it('badges a hit from another folder', () => {
            const hit = row({ msg: msg({ folder: 'Archiv/2025' }) });
            const { container, unmount } = render(<MailMessageList {...base} rows={[hit]} isSearchMode currentFolder="INBOX" />);

            expect(textOf(container)).toContain('Archiv/2025');
            unmount();
        });

        it('stays quiet for a hit from the folder already shown', () => {
            const hit = row({ msg: msg({ folder: 'INBOX' }) });
            const { container, unmount } = render(<MailMessageList {...base} rows={[hit]} isSearchMode currentFolder="INBOX" />);

            expect(container.querySelectorAll('[title^="Gefunden in"]')).toHaveLength(0);
            unmount();
        });

        it('marks a hit that only lives in the archive', () => {
            const hit = row({ msg: msg({ source: 'stored', stored_id: 9 }) });
            const { container, unmount } = render(<MailMessageList {...base} rows={[hit]} isSearchMode currentFolder="INBOX" />);

            expect(textOf(container)).toContain('Archiv');
            unmount();
        });

        it('says nothing about folders outside search', () => {
            const hit = row({ msg: msg({ folder: 'Archiv/2025' }) });
            const { container, unmount } = render(<MailMessageList {...base} rows={[hit]} currentFolder="INBOX" />);

            expect(textOf(container)).not.toContain('Archiv/2025');
            unmount();
        });
    });

    it('lets the product hang its own thing on a row', () => {
        const { container, unmount } = render(
            <MailMessageList {...base} rows={[row()]} rowAccessory={(r) => <span>zugewiesen:{r.msg.uid}</span>} />,
        );

        expect(textOf(container)).toContain('zugewiesen:1');
        unmount();
    });

    it('marks the open message, but never a stored reply', () => {
        const stored = row({ msg: msg({ uid: 0, source: 'stored' }), isOutbound: true });
        const { container, unmount } = render(<MailMessageList {...base} rows={[stored]} openedUid={0} />);

        expect(container.querySelector('[data-slot="mail-message-row"]')?.className).not.toContain('bg-primary/10');
        unmount();
    });
});

describe('wer scrollt', () => {
    it('bringt KEIN eigenes Layout mit', () => {
        // Am 21.09.2026 standen hier kurz `flex-1 min-h-0 overflow-y-auto`.
        // Der Manager wickelt seine Liste aber seit je in ein eigenes
        // ScrollArea — und ein Scroll-Container innerhalb eines
        // Scroll-Containers bekommt nie eine begrenzte Hoehe. Danach ging dort
        // gar nichts mehr.
        //
        // Ein geteiltes Bauteil weiss nicht, worin es steckt. Wo gescrollt
        // wird, entscheidet, wer die Spalte fuellt.
        const { container, unmount } = render(<MailMessageList rows={[]} labels={labels} />);
        const wurzel = container.querySelector('[data-slot="mail-message-list"]');

        expect(wurzel?.className ?? '').toBe('');

        unmount();
    });

    it('zeigt Absender und Betreff beim Ueberfahren vollstaendig', () => {
        // Die Spalte ist 320 px schmal; fast jeder Name und fast jeder Betreff
        // sind abgeschnitten. Wer wissen will, worum es geht, soll dafuer nicht
        // die Mail oeffnen muessen.
        const { container, unmount } = render(
            <MailMessageList
                rows={[row({ msg: msg({ from_name: 'Eine sehr lange Absenderin mit Titel', subject: 'Ein ebenso langer Betreff, der niemals passt' }) })]}
                {...base}
            />,
        );

        const titel = [...container.querySelectorAll('span[title]')].map((e) => e.getAttribute('title'));

        expect(titel).toContain('Eine sehr lange Absenderin mit Titel');
        expect(titel).toContain('Ein ebenso langer Betreff, der niemals passt');
        unmount();
    });

    it('gibt dem Absender mehr Gewicht als dem Betreff', () => {
        // Damit sich die beiden Zeilen voneinander abheben. Das Gewicht traegt
        // dabei WEITER die Unterscheidung gelesen/ungelesen — es soll nur nicht
        // mehr so aussehen, als waere der Betreff genauso wichtig wie der Name.
        const { container, unmount } = render(
            <MailMessageList
                rows={[row({ msg: msg({ is_read: true, from_name: 'Anna', subject: 'Betreff' }) })]}
                {...base}
            />,
        );

        const absender = container.querySelector('span[title="Anna"]')!;
        const betreff = container.querySelector('span[title="Betreff"]')!;

        expect(absender.className).toContain('font-medium');
        expect(betreff.className).toContain('font-normal');
        expect(betreff.className).toContain('text-muted-foreground');
        unmount();
    });

    it('zeichnet gar keine Vorschauzeile mehr', () => {
        // JMAP liefert einen Auszug des Nachrichtenkoerpers gratis mit, IMAP
        // nicht. Die Liste sah damit je nach Postfach verschieden aus —
        // dasselbe Produkt, zwei Darstellungen, und der Unterschied lag im
        // Protokoll. Gebraucht wurde der volle Betreff, und der steht im
        // `title`.
        const { container, unmount } = render(
            <MailMessageList rows={[row({ msg: msg({ preview: 'Guten Tag, anbei die Rechnung …' }) })]} {...base} />,
        );

        expect(container.querySelector('p')).toBeNull();
        expect(container.textContent).not.toContain('anbei die Rechnung');
        unmount();
    });
});
