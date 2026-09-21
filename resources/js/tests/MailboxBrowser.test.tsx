import { describe, expect, it } from 'vitest';
import MailboxBrowser from '../src/MailboxBrowser';
import { render, textOf } from './support/render';

const panes = {
    folders: <span>ORDNER</span>,
    list: <span>LISTE</span>,
    view: <span>ANSICHT</span>,
};

describe('MailboxBrowser', () => {
    it('places all three columns', () => {
        const { container, unmount } = render(<MailboxBrowser {...panes} />);

        expect(container.querySelector('[data-slot="mailbox-folders"]')).not.toBeNull();
        expect(container.querySelector('[data-slot="mailbox-list"]')).not.toBeNull();
        expect(container.querySelector('[data-slot="mailbox-view"]')).not.toBeNull();
        unmount();
    });

    it('drops the folder column entirely when it is hidden', () => {
        // Not merely narrow: a collapsed column that still renders keeps its
        // border and its scroll container, and both show.
        const { container, unmount } = render(<MailboxBrowser {...panes} showFolders={false} />);

        expect(container.querySelector('[data-slot="mailbox-folders"]')).toBeNull();
        expect(textOf(container)).not.toContain('ORDNER');
        expect(textOf(container)).toContain('LISTE');
        unmount();
    });

    it('takes a toolbar above the columns', () => {
        const { container, unmount } = render(<MailboxBrowser {...panes} toolbar={<span>LEISTE</span>} />);

        expect(textOf(container)).toContain('LEISTE');
        unmount();
    });

    it('takes a column of the product beside the reading pane', () => {
        const { container, unmount } = render(<MailboxBrowser {...panes} aside={<span>ASSISTENT</span>} />);

        expect(textOf(container)).toContain('ASSISTENT');
        unmount();
    });

    it('leaves the aside out when there is none', () => {
        const { container, unmount } = render(<MailboxBrowser {...panes} />);

        // Three columns and nothing else — an empty fourth would still take
        // its border and its share of the row.
        const row = container.querySelector('[data-slot="mailbox-folders"]')?.parentElement;
        expect(row?.children).toHaveLength(3);
        unmount();
    });

    it('lets the product widen the list', () => {
        const { container, unmount } = render(<MailboxBrowser {...panes} listWidth="w-96" />);

        expect(container.querySelector('[data-slot="mailbox-list"]')?.className).toContain('w-96');
        unmount();
    });

    it('laesst jede Spalte selbst scrollen', () => {
        // Der Rahmen ist so hoch wie das Fenster und schneidet ab, was darueber
        // hinausgeht. Fehlt einer Spalte ihr eigener Scroll-Container, ist ihr
        // unterer Teil schlicht nicht mehr erreichbar — ohne Fehler, ohne
        // Meldung, die Zeilen sind einfach weg.
        //
        // Der Liste fehlte er bis zum 21.09.2026. Gemeldet wurde es erst, als
        // eingeschaltete Konversationen die Liste laenger machten.
        const { container, unmount } = render(<MailboxBrowser {...panes} />);

        for (const spalte of ['mailbox-folders', 'mailbox-list']) {
            expect(container.querySelector(`[data-slot="${spalte}"]`)?.className, spalte).toContain('overflow-y-auto');
        }

        unmount();
    });
});
