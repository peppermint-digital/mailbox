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
        load: async () => ({ assignments: [zuweisung], reachable: true }),
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

/**
 * „Nicht geholt" ist nicht „niemand zuständig" (19.10.2026).
 *
 * Seit die Zuständigkeit in der Mitte liegt, gibt es den Fall, dass niemand zu
 * fragen war. Beide Lagen ergeben eine leere Liste — und nur eine davon ist
 * eine Aussage. Wer sie verwechselt, zeigt jede Mail als frei, zwei Leute
 * fangen dieselbe Sache an, und es gibt keine Meldung.
 */
function Erreichbarkeit({ antwort }: { antwort: 'gut' | 'leer' | 'stumm' | 'wirft' }) {
    const quelle = {
        users: async () => [],
        load: async () => {
            if (antwort === 'wirft') {
                throw new Error('Zeitüberschreitung.');
            }

            return {
                assignments: antwort === 'gut' ? [zuweisung] : [],
                reachable: antwort !== 'stumm',
            };
        },
        assign: async () => true,
        unassign: async () => true,
    };

    const { erreichbar, assignments } = useMailAssignments({ accountId: 1, source: quelle });

    return <span data-slot="stand">{`${erreichbar ? 'erreichbar' : 'stumm'}:${assignments.length}`}</span>;
}

async function standFuer(antwort: 'gut' | 'leer' | 'stumm' | 'wirft'): Promise<string> {
    const { container, unmount } = render(<Erreichbarkeit antwort={antwort} />);

    await new Promise((r) => setTimeout(r, 0));

    const text = container.querySelector('[data-slot="stand"]')?.textContent ?? '';
    unmount();

    return text;
}

describe('useMailAssignments.erreichbar', () => {
    it('unterscheidet eine leere Liste von einer, die nicht zu holen war', async () => {
        expect(await standFuer('leer')).toBe('erreichbar:0');
        expect(await standFuer('stumm')).toBe('stumm:0');
    });

    it('meldet auch einen geworfenen Fehler als nicht erreichbar', async () => {
        // Die Ansicht bleibt benutzbar — aber sie behauptet nicht, dass
        // niemand zuständig ist.
        expect(await standFuer('wirft')).toBe('stumm:0');
    });

    it('gilt bis zur ersten Antwort als erreichbar', async () => {
        // Ein Hinweis, der schon beim Laden steht, wäre eine Störungsmeldung
        // für einen normalen Zustand.
        const { container, unmount } = render(<Erreichbarkeit antwort="gut" />);

        expect(container.querySelector('[data-slot="stand"]')?.textContent).toBe('erreichbar:0');

        unmount();
    });
});
