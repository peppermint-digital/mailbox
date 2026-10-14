import { describe, expect, it } from 'vitest';
import { useMailAssignments, type MailAssignment } from '../src/useMailAssignments';
import { render } from './support/render';

/**
 * Eine Zuweisung gehört der KETTE, nicht der einzelnen Nachricht (#3851).
 *
 * Diese Zusage lag bis zum 14.10.2026 im Manager und wurde dort serverseitig
 * geprüft. Seit #7690 schlägt der Browser nach, und seit #7708 rechnet der
 * Server nur noch die Kettenkennung aus. Die Regel selbst wohnt damit hier —
 * und war bis zu diesem Test ungeprüft.
 */

const zuweisung: MailAssignment = {
    message_id: '<b@example.com>',
    thread_id: 'a@example.com',
    user_id: 7,
    name: 'Volker Tolksdorf',
    initials: 'VT',
};

/** Eine Hülle, die nur den Haken aufruft und sein Ergebnis sichtbar macht. */
function Probe({ nachricht }: { nachricht: { message_id?: string; thread_id?: string | null } }) {
    const quelle = {
        users: async () => [],
        load: async () => [zuweisung],
        assign: async () => true,
        unassign: async () => true,
    };

    const zuweisungen = useMailAssignments({ accountId: 1, source: quelle });
    const treffer = zuweisungen.fuer(nachricht as never);

    return <span data-slot="treffer">{treffer ? treffer.name : 'keiner'}</span>;
}

async function trefferFuer(nachricht: { message_id?: string; thread_id?: string | null }): Promise<string> {
    const { container, unmount } = render(<Probe nachricht={nachricht} />);

    // Der Haken lädt beim Einhängen — einmal durchatmen lassen.
    await new Promise((r) => setTimeout(r, 0));

    const text = container.querySelector('[data-slot="treffer"]')?.textContent ?? '';
    unmount();

    return text;
}

describe('useMailAssignments.fuer', () => {
    it('findet die Zuweisung über die Kette, auch bei einer anderen Nachricht', async () => {
        // Genau der Fall aus #3851: Antwort c kommt in derselben Kette an und
        // war an keiner Stelle selbst zugewiesen.
        expect(
            await trefferFuer({ message_id: '<c@example.com>', thread_id: 'a@example.com' }),
        ).toBe('Volker Tolksdorf');
    });

    it('findet sie auch über die Nachricht, wenn keine Kette bekannt ist', async () => {
        // Der Rückfall für Zuweisungen aus der Zeit vor den Kettenkennungen.
        expect(await trefferFuer({ message_id: '<b@example.com>' })).toBe('Volker Tolksdorf');
    });

    it('erbt nichts aus einer fremden Kette', async () => {
        expect(
            await trefferFuer({ message_id: '<x@example.com>', thread_id: 'ganz-anders' }),
        ).toBe('keiner');
    });
});
