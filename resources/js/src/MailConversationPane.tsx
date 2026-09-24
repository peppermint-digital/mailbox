import { MessagesSquare } from 'lucide-react';
import type { ReactNode } from 'react';
import { MailConversation, type ConversationEntry, type MailConversationLabels } from './MailConversation';
import type { MailConversationState } from './useMailConversation';
import { Button } from './ui/button';

/**
 * Der Umschalter und das, was er umschaltet — beides aus dem Paket.
 *
 * ## Warum das nicht jedes Produkt selbst zusammensetzt
 *
 * Weil es dann nur eines tut. Der Verlauf lag monatelang fertig im Paket und
 * war trotzdem nur in AI Brain zu sehen: Die Anzeige war dort von Hand
 * verdrahtet, und „liegt im Paket" hiess in Wahrheit „die Bausteine liegen im
 * Paket". Ein Produkt, das den Umschalter nicht baut, hat kein kaputtes
 * Feature — es hat gar keines, und niemandem faellt es auf.
 *
 * Hier kommt beides zusammen: ein Element um die gewohnte Nachrichtenansicht
 * gelegt, und der Verlauf ist da.
 */
export interface MailConversationPaneLabels extends MailConversationLabels {
    /** Aufschrift, solange die gewohnte Ansicht steht. */
    showConversation: string;
    /** Aufschrift, solange der Verlauf steht. */
    showFullMessage: string;
}

export interface MailConversationPaneProps {
    state: MailConversationState;
    labels: MailConversationPaneLabels;
    /** Die eigenen Adressen des Postfachs — sonst stehen alle Blasen auf einer Seite. */
    ownAddresses?: string[];
    /**
     * Zurueck in die gewohnte Ansicht, bei genau dieser Nachricht. Das
     * Ausschalten des Verlaufs besorgt die Huelle selbst.
     */
    onOpenOriginal?: (entry: ConversationEntry) => void;
    formatDateTime?: (iso: string) => string;
    /** Die gewohnte Nachrichtenansicht des Produkts. */
    children: ReactNode;
}

export function MailConversationPane({
    state,
    labels,
    ownAddresses = [],
    onOpenOriginal,
    formatDateTime,
    children,
}: MailConversationPaneProps) {
    return (
        <>
            {state.available && (
                <div className="flex justify-end border-b px-3 py-1.5" data-slot="mail-conversation-toggle">
                    <Button
                        size="sm"
                        variant={state.on ? 'default' : 'ghost'}
                        onClick={() => (state.on ? state.hide() : state.show())}
                    >
                        <MessagesSquare className="mr-1 h-4 w-4" />
                        {state.on ? labels.showFullMessage : labels.showConversation}
                    </Button>
                </div>
            )}

            {state.on ? (
                <MailConversation
                    entries={state.entries}
                    loading={state.loading}
                    ownAddresses={ownAddresses}
                    labels={labels}
                    formatDateTime={formatDateTime}
                    onOpenOriginal={
                        onOpenOriginal &&
                        ((eintrag) => {
                            state.hide();
                            onOpenOriginal(eintrag);
                        })
                    }
                />
            ) : (
                children
            )}
        </>
    );
}
