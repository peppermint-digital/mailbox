import { describe, expect, it } from 'vitest';
import { MailAssignmentPicker } from '../src/MailAssignmentPicker';
import { render } from './support/render';

const labels = {
    assign: 'Jemandem zuweisen',
    assignedTo: (name: string) => `Zuständig: ${name}`,
    unassign: 'Zuweisung aufheben',
    noUsers: 'Niemand verfügbar',
};

const personen = [{ id: 1, name: 'Bastian Henneberg', initials: 'BH' }];

const zuweisung = {
    message_id: '<a@x.de>',
    thread_id: 'x',
    user_id: 1,
    name: 'Bastian Henneberg',
    initials: 'BH',
};

describe('MailAssignmentPicker', () => {
    it('macht das Abzeichen zum Auslöser des Menüs, auch wenn schon zugewiesen ist', () => {
        /*
         * Der Fall, der bis zum 14.10.2026 kaputt war: `DropdownMenuTrigger`
         * lag AUSSEN um `<Tooltip>` — einen reinen Kontext-Anbieter ohne
         * eigenes Element. Seine Eigenschaften landeten damit nirgends, und
         * ein zugewiesenes Abzeichen öffnete beim Klick gar nichts. Man kam
         * weder an eine andere Person noch an „Zuweisung aufheben".
         *
         * Gemessen wird deshalb NICHT, ob ein Abzeichen erscheint — das tat
         * es vorher auch —, sondern ob es den Auslöser trägt. Radix setzt
         * `aria-haspopup="menu"` nur auf das Element, das wirklich öffnet.
         */
        const { container, unmount } = render(
            <MailAssignmentPicker
                labels={labels}
                users={personen}
                assignment={zuweisung}
                onAssign={() => undefined}
                onUnassign={() => undefined}
            />,
        );

        const abzeichen = container.querySelector('[data-slot="mail-assignment-badge"]');

        expect(abzeichen).not.toBeNull();
        expect(abzeichen?.getAttribute('aria-haspopup')).toBe('menu');
        unmount();
    });

    it('trägt den Auslöser auch im leeren Zustand', () => {
        // Die Gegenseite: Der Weg „noch niemandem zugewiesen" ging schon immer
        // — ohne diesen Test wüsste man nicht, ob der Umbau ihn mitgenommen hat.
        const { container, unmount } = render(
            <MailAssignmentPicker
                labels={labels}
                users={personen}
                assignment={null}
                onAssign={() => undefined}
                onUnassign={() => undefined}
            />,
        );

        const knopf = container.querySelector('[data-slot="mail-assignment-empty"]');

        expect(knopf?.getAttribute('aria-haspopup')).toBe('menu');
        unmount();
    });
});
