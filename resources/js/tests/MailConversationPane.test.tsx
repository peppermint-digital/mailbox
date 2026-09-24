import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { MailConversationPane, type MailConversationPaneLabels } from '../src/MailConversationPane';
import { conversationRoutes, useMailConversation } from '../src/useMailConversation';
import { render, textOf } from './support/render';

/**
 * Der Verlauf kommt aus dem Paket — Umschalter eingeschlossen.
 *
 * Genau daran hing es: Die Bausteine lagen im Paket, zusammengesetzt hat sie
 * nur ein Produkt. Diese Tests bestehen darauf, dass das Zusammensetzen
 * ebenfalls im Paket passiert.
 */
const texte: MailConversationPaneLabels = {
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
    showConversation: 'Gesprächsverlauf',
    showFullMessage: 'Ganze Nachricht',
};

const EINTRAG = {
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
};

/** Eine Antwort, wie die Endpunkte sie liefern. */
function antwort(daten: unknown) {
    return Promise.resolve({ json: () => Promise.resolve(daten) } as Response);
}

function Huelle({
    fetch,
    thread = 'kette@example.test',
    onOpenOriginal,
}: {
    fetch: typeof globalThis.fetch;
    thread?: string;
    onOpenOriginal?: (e: typeof EINTRAG) => void;
}) {
    const verlauf = useMailConversation({
        routes: conversationRoutes('/mailbox'),
        accountId: 3,
        thread,
        fetch,
    });

    return (
        <MailConversationPane state={verlauf} labels={texte} onOpenOriginal={onOpenOriginal as never}>
            <p>Die ganze Nachricht mit allem Drum und Dran.</p>
        </MailConversationPane>
    );
}

function knopf(container: HTMLElement, aufschrift: string): HTMLElement | undefined {
    return [...container.querySelectorAll('button')].find((b) => (b.textContent ?? '').includes(aufschrift));
}

/** Warten, bis die angestossenen Abfragen durch sind. */
async function ruhe(): Promise<void> {
    await act(async () => {
        await Promise.resolve();
        await Promise.resolve();
    });
}

describe('MailConversationPane', () => {
    it('zeigt keinen Umschalter, wenn die Kette nicht vollständig abgelegt ist', async () => {
        // Ein Knopf, der beim Klick erklären muss, warum er nichts kann, ist
        // schlimmer als keiner.
        const { container } = render(<Huelle fetch={() => antwort({ available: false })} />);
        await ruhe();

        expect(knopf(container, 'Gesprächsverlauf')).toBeUndefined();
        expect(textOf(container)).toContain('Die ganze Nachricht');
    });

    it('holt den Verlauf erst auf Klick und zeigt ihn statt der Nachricht', async () => {
        const gerufen: string[] = [];
        const holen = ((url: string) => {
            gerufen.push(url);

            return url.includes('conversation-available')
                ? antwort({ available: true })
                : antwort({ entries: [EINTRAG] });
        }) as unknown as typeof globalThis.fetch;

        const { container } = render(<Huelle fetch={holen} />);
        await ruhe();

        // Die teure Frage wird nicht gestellt, solange niemand hinsieht.
        expect(gerufen).toHaveLength(1);

        const umschalter = knopf(container, 'Gesprächsverlauf')!;
        await act(async () => {
            umschalter.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });
        await ruhe();

        expect(textOf(container)).toContain('Die Freigabe ist da.');
        expect(textOf(container)).not.toContain('Die ganze Nachricht');
        expect(gerufen[1]).toContain('/mailbox/3/conversation?thread=');
    });

    it('bringt „Original öffnen" zurück in die gewohnte Ansicht', async () => {
        const geoeffnet = vi.fn();
        const holen = ((url: string) =>
            url.includes('conversation-available')
                ? antwort({ available: true })
                : antwort({ entries: [EINTRAG] })) as unknown as typeof globalThis.fetch;

        const { container } = render(<Huelle fetch={holen} onOpenOriginal={geoeffnet} />);
        await ruhe();
        await act(async () => {
            knopf(container, 'Gesprächsverlauf')!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });
        await ruhe();

        await act(async () => {
            knopf(container, 'Original öffnen')!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });

        expect(geoeffnet).toHaveBeenCalledWith(expect.objectContaining({ uid: 42 }));
        // Ohne dieses Zurückschalten führte der Knopf ins Nichts: Die Ansicht
        // bliebe der Verlauf, in dem man gerade steht.
        expect(textOf(container)).toContain('Die ganze Nachricht');
    });

    it('fragt gar nicht erst, wenn keine Nachricht offen ist', async () => {
        const holen = vi.fn(() => antwort({ available: true })) as unknown as typeof globalThis.fetch;

        render(<Huelle fetch={holen} thread="" />);
        await ruhe();

        expect(holen).not.toHaveBeenCalled();
    });

    it('nimmt einen unerreichbaren Endpunkt als „kein Verlauf", nicht als Fehler', async () => {
        const { container } = render(
            <Huelle fetch={(() => Promise.reject(new Error('weg'))) as unknown as typeof globalThis.fetch} />,
        );
        await ruhe();

        expect(knopf(container, 'Gesprächsverlauf')).toBeUndefined();
        expect(textOf(container)).toContain('Die ganze Nachricht');
    });
});
