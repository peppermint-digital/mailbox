import { describe, expect, it, vi } from 'vitest';
import { MailConversation, type ConversationEntry, type MailConversationLabels } from '../src/MailConversation';
import { click, render, textOf } from './support/render';

const texte: MailConversationLabels = {
    empty: 'Noch nichts hier.',
    loading: 'Wird geladen …',
    showOmitted: 'Weggelassenes zeigen',
    hideOmitted: 'Weggelassenes ausblenden',
    quote: 'Zitat',
    signature: 'Signatur',
    footer: 'Fußzeile',
    openOriginal: 'Original öffnen',
    notInMailbox: 'Nicht mehr im Postfach',
    attachments: (n) => `${n} Anhang`,
    nothingWritten: 'Kein Text — nur ein Anhang.',
    purged: (wann, wer) => `Aus der Ablage entfernt am ${wann} durch ${wer}.`,
};

function eintrag(werte: Partial<ConversationEntry> = {}): ConversationEntry {
    return {
        id: 1,
        message_id: '<a@example.test>',
        from: { email: 'kunde@example.test', name: 'Kunde' },
        sent_at: '2026-09-22T10:00:00+02:00',
        subject: 'Angebot',
        content: 'Die Freigabe ist da.',
        quote: null,
        signature: null,
        footer: null,
        has_attachments: false,
        attachment_count: 0,
        in_mailbox: true,
        folder: 'INBOX',
        uid: 42,
        ...werte,
    };
}

/** Der Knopf mit dieser Aufschrift. */
function knopf(container: HTMLElement, aufschrift: string): HTMLElement | undefined {
    return [...container.querySelectorAll('button')].find((b) => (b.textContent ?? '').includes(aufschrift));
}

describe('MailConversation', () => {
    it('zeigt den Text jeder Nachricht', () => {
        const { container } = render(<MailConversation entries={[eintrag()]} labels={texte} />);

        expect(textOf(container)).toContain('Die Freigabe ist da.');
        expect(textOf(container)).toContain('Kunde');
    });

    it('zeigt das Weggelassene erst auf Klick', () => {
        // Der Punkt, an dem der Verlauf vertretbar wird: Man kann ihm ansehen,
        // was er verschweigt.
        const { container } = render(
            <MailConversation
                entries={[eintrag({ signature: 'Mit freundlichen Grüßen Chris', quote: '> Und?' })]}
                labels={texte}
            />,
        );

        expect(textOf(container)).not.toContain('Mit freundlichen Grüßen');

        click(knopf(container, 'Weggelassenes zeigen')!);

        expect(textOf(container)).toContain('Mit freundlichen Grüßen');
        expect(textOf(container)).toContain('> Und?');
    });

    it('bietet das Weggelassene nicht an, wenn nichts weggelassen wurde', () => {
        const { container } = render(<MailConversation entries={[eintrag()]} labels={texte} />);

        expect(knopf(container, 'Weggelassenes zeigen')).toBeUndefined();
    });

    it('stellt eigene Nachrichten auf die andere Seite', () => {
        // Ein Verlauf, in dem man die eigenen Beitraege nicht erkennt, ist
        // keiner. Gross-/Kleinschreibung darf dabei nicht zaehlen.
        const { container } = render(
            <MailConversation
                entries={[eintrag({ from: { email: 'WIR@example.test', name: 'Wir' } })]}
                labels={texte}
                ownAddresses={['wir@example.test']}
            />,
        );

        expect(container.querySelector('[data-slot="mail-conversation-entry"]')?.className).toContain('justify-end');
    });

    it('stellt fremde Nachrichten auf die eigene Seite', () => {
        const { container } = render(
            <MailConversation entries={[eintrag()]} labels={texte} ownAddresses={['wir@example.test']} />,
        );

        expect(container.querySelector('[data-slot="mail-conversation-entry"]')?.className).not.toContain('justify-end');
    });

    it('öffnet das Original', () => {
        const geoeffnet = vi.fn();

        const { container } = render(
            <MailConversation entries={[eintrag()]} labels={texte} onOpenOriginal={geoeffnet} />,
        );

        click(knopf(container, 'Original öffnen')!);

        expect(geoeffnet).toHaveBeenCalledWith(expect.objectContaining({ uid: 42 }));
    });

    it('bietet keinen toten Knopf für eine Nachricht, die nicht mehr im Postfach liegt', () => {
        const { container } = render(
            <MailConversation
                entries={[eintrag({ in_mailbox: false, folder: null, uid: null })]}
                labels={texte}
                onOpenOriginal={vi.fn()}
            />,
        );

        expect(knopf(container, 'Original öffnen')).toBeUndefined();
        expect(textOf(container)).toContain('Nicht mehr im Postfach');
    });

    it('sagt es, wenn eine Nachricht nur aus einem Anhang bestand', () => {
        const { container } = render(
            <MailConversation
                entries={[eintrag({ content: '   ', has_attachments: true, attachment_count: 1 })]}
                labels={texte}
            />,
        );

        expect(textOf(container)).toContain('Kein Text — nur ein Anhang.');
        expect(textOf(container)).toContain('1 Anhang');
    });

    it('zeigt eine leere Kette als leer und nicht als Fehler', () => {
        const { container } = render(<MailConversation entries={[]} labels={texte} />);

        expect(textOf(container)).toContain('Noch nichts hier.');
    });

    it('zeigt Platzhalter, solange geladen wird', () => {
        const { container } = render(<MailConversation entries={[]} labels={texte} loading />);

        expect(container.querySelector('[data-slot="mail-conversation-loading"]')).not.toBeNull();
        expect(textOf(container)).not.toContain('Noch nichts hier.');
    });
});

describe('MailConversation — entfernte Nachrichten', () => {
    it('zeigt an der Stelle einer entfernten Nachricht einen Merker', () => {
        // Bis v0.120.0 stand hier eine leere Blase mit „Kein Text — nur ein
        // Anhang": eine stille Falschaussage über eine Nachricht, die es sehr
        // wohl gab. Eine sichtbare Lücke ist besser als eine unsichtbare.
        const { container } = render(
            <MailConversation
                entries={[
                    eintrag(),
                    eintrag({
                        id: 2,
                        content: '',
                        subject: null,
                        from: { email: null, name: null },
                        purged_at: '2026-09-25T09:00:00+02:00',
                        purged_by: 'bastian',
                    }),
                ]}
                labels={texte}
            />,
        );

        expect(container.querySelectorAll('[data-slot="mail-conversation-purged"]')).toHaveLength(1);
        expect(textOf(container)).toContain('Aus der Ablage entfernt');
        expect(textOf(container)).toContain('bastian');
        // Und ausdrücklich NICHT die alte Behauptung.
        expect(textOf(container)).not.toContain('Kein Text');
        // Die gebliebene Nachricht steht weiter da.
        expect(textOf(container)).toContain('Die Freigabe ist da.');
    });

    it('bietet beim Merker keinen Weg zum Original an', () => {
        // Es gibt keines mehr. Ein Knopf, der das verspricht, wäre der Grund,
        // aus dem man Löschungen nicht glaubt.
        const { container } = render(
            <MailConversation
                entries={[eintrag({ content: '', purged_at: '2026-09-25T09:00:00+02:00', purged_by: 'bastian' })]}
                labels={texte}
                onOpenOriginal={vi.fn()}
            />,
        );

        expect(knopf(container, 'Original öffnen')).toBeUndefined();
        expect(knopf(container, 'Weggelassenes zeigen')).toBeUndefined();
    });

    it('lässt gebliebene Nachrichten unberührt', () => {
        const { container } = render(<MailConversation entries={[eintrag()]} labels={texte} />);

        expect(container.querySelectorAll('[data-slot="mail-conversation-purged"]')).toHaveLength(0);
    });
});
