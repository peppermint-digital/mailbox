import { describe, expect, it } from 'vitest';
import ForwardComposer, { type ForwardComposerLabels } from '../src/inertia/ForwardComposer';
import { render } from './support/render';

/**
 * The composer is the product's. These tests watch what ForwardComposer hands
 * it — that is the whole contract between package and product.
 */
let gesehen: Record<string, unknown> = {};

function ComposerStub(props: Record<string, unknown>) {
    gesehen = props;

    // Kopf und Zitat werden dem Composer übergeben, nicht selbst gezeichnet —
    // ein Stub, der sie wegwirft, misst die Hälfte nicht.
    return (
        <div data-testid="composer">
            {props.header as never}
            {props.quoted as never}
        </div>
    );
}

const labels: ForwardComposerLabels = {
    forward: 'Weiterleiten',
    from: 'Von',
    forwardedMessage: 'Weitergeleitete Nachricht',
    originalAttachments: (anzahl, namen) => `${anzahl} Anhänge sind dabei: ${namen}`,
    forwarded: 'Weitergeleitet',
    draftSaved: 'Entwurf gesichert',
    draftFailed: (status) => `Entwurf nicht gesichert (${status}).`,
    failed: (status) => `Nicht weitergeleitet (${status}).`,
    unknownError: 'Unbekannter Fehler',
    quote: {
        labels: {
            repliedOn: () => '',
            forwardHeadline: '--- Weitergeleitet ---',
            from: 'Von:',
            date: 'Datum:',
            subject: 'Betreff:',
            to: 'An:',
        },
        formatDate: (iso: string) => iso,
    },
    body: {
        empty: 'Kein Inhalt',
        remoteBlocked: 'Blockiert',
        loadImages: 'Bilder laden',
        frameTitle: 'Inhalt',
    },
};

function weiterleiten(endpoints: { forward: string; draft?: string }) {
    return render(
        <ForwardComposer
            account={{ id: 1, name: 'Büro', email: 'buero@example.test' }}
            source={{ type: 'imap', folder: 'INBOX', uid: 42 }}
            subject="Rechnung"
            fromAddress="kunde@example.test"
            date="2026-10-14T08:00:00Z"
            bodyText="Anbei die Rechnung"
            originalAttachments={[{ filename: 'rechnung.pdf' }]}
            labels={labels}
            endpoints={endpoints}
            composer={ComposerStub}
            notify={{ success: () => undefined }}
            onSent={() => undefined}
            onCancel={() => undefined}
        />,
    );
}

describe('ForwardComposer', () => {
    it('bietet „Entwurf sichern" nur an, wenn das Produkt Entwürfe führt', () => {
        // Ein Knopf, der nur 404 antworten kann, ist schlimmer als keiner:
        // jemand schließt das Fenster im Vertrauen darauf.
        const ohne = weiterleiten({ forward: '/mailbox/1/forward' });

        expect(gesehen.allowDraft).toBe(false);
        ohne.unmount();

        const mit = weiterleiten({ forward: '/mailbox/1/forward', draft: '/mailbox/1/draft' });

        expect(gesehen.allowDraft).toBe(true);
        mit.unmount();
    });

    it('nennt die Anhänge der Ursprungsnachricht, bevor etwas hinausgeht', () => {
        // Der Absender soll SEHEN, dass die Datei mitgeht — sonst hängt er sie
        // zur Sicherheit noch einmal an, oder er hält sie für vergessen.
        const { container, unmount } = weiterleiten({ forward: '/mailbox/1/forward' });

        expect(container.textContent).toContain('1 Anhänge sind dabei: rechnung.pdf');
        unmount();
    });

    it('setzt „Fwd:" vor den Betreff, aber nicht zweimal', () => {
        const { unmount } = weiterleiten({ forward: '/mailbox/1/forward' });

        expect(gesehen.subject).toBe('Fwd: Rechnung');
        unmount();
    });
});
