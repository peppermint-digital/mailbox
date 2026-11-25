import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { MailboxPaneContext, type MailboxPane } from '../src/MailboxPane';
import { MailboxMobilLeiste } from '../src/MailboxMobilLeiste';
import { MailVollbild } from '../src/MailVollbild';
import { germanLabels } from '../src/germanLabels';
import { click, render } from './support/render';

/**
 * Das Vollbild einer Mail (25.11.2026): oben nur der Betreff, darunter der
 * Text — und es liegt ueber der GANZEN Seite, nicht nur im Rahmen.
 */
function imRahmen(pane: MailboxPane, kind: React.ReactElement) {
    return <MailboxPaneContext.Provider value={{ pane, showPane: () => {} }}>{kind}</MailboxPaneContext.Provider>;
}

describe('MailVollbild', () => {
    it('zeichnet sich unter document.body, mit Betreff und Text und sonst nichts', () => {
        const { container, unmount } = render(
            imRahmen('view', <MailVollbild betreff="Angebot Q4" bodyText="Hallo Bastian" labels={germanLabels.vollbild} onSchliessen={() => {}} />),
        );

        const vollbild = document.querySelector('[data-slot="mail-vollbild"]');

        expect(vollbild).not.toBeNull();
        // Per Portal — sonst koennte ein `transform` an einem Vorfahren es einsperren.
        expect(container.contains(vollbild)).toBe(false);
        expect(vollbild?.parentElement).toBe(document.body);
        expect(document.querySelector('[data-slot="mail-vollbild-titel"]')?.textContent).toBe('Angebot Q4');
        expect(vollbild?.textContent).toContain('Hallo Bastian');
        expect(vollbild?.querySelectorAll('button')).toHaveLength(1);

        unmount();
        expect(document.querySelector('[data-slot="mail-vollbild"]')).toBeNull();
    });

    it('nennt eine Mail ohne Betreff trotzdem', () => {
        const { unmount } = render(imRahmen('view', <MailVollbild betreff="  " bodyText="x" labels={germanLabels.vollbild} onSchliessen={() => {}} />));

        expect(document.querySelector('[data-slot="mail-vollbild-titel"]')?.textContent).toBe('(Kein Betreff)');
        unmount();
    });

    it('schliesst per Knopf und per Escape, und sperrt solange das Scrollen der Seite', () => {
        const onSchliessen = vi.fn();
        const { unmount } = render(imRahmen('view', <MailVollbild betreff="A" bodyText="x" labels={germanLabels.vollbild} onSchliessen={onSchliessen} />));

        expect(document.documentElement.style.overflow).toBe('hidden');

        click(document.querySelector('[data-slot="mail-vollbild-schliessen"]')!);
        act(() => {
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        });
        expect(onSchliessen).toHaveBeenCalledTimes(2);

        unmount();
        expect(document.documentElement.style.overflow).toBe('');
    });

    it('schliesst sich selbst, sobald der Rahmen nicht mehr die Leseansicht zeigt', () => {
        const onSchliessen = vi.fn();
        const { rerender, unmount } = render(imRahmen('view', <MailVollbild betreff="A" bodyText="x" labels={germanLabels.vollbild} onSchliessen={onSchliessen} />));

        rerender(imRahmen('list', <MailVollbild betreff="A" bodyText="x" labels={germanLabels.vollbild} onSchliessen={onSchliessen} />));

        expect(onSchliessen).toHaveBeenCalledTimes(1);
        expect(document.querySelector('[data-slot="mail-vollbild"]')).toBeNull();
        unmount();
    });
});

describe('MailboxMobilLeiste — der Griff zum Vollbild', () => {
    it('steht nur beim Lesen da, und nur mit Rueckruf', () => {
        const onVollbild = vi.fn();

        const liste = render(imRahmen('list', <MailboxMobilLeiste ordnerName="Posteingang" onVollbild={onVollbild} />));
        expect(liste.container.querySelector('[data-slot="mailbox-mobil-vollbild"]')).toBeNull();
        liste.unmount();

        const ohne = render(imRahmen('view', <MailboxMobilLeiste ordnerName="Posteingang" absender="Anna" />));
        expect(ohne.container.querySelector('[data-slot="mailbox-mobil-vollbild"]')).toBeNull();
        ohne.unmount();

        const lesen = render(imRahmen('view', <MailboxMobilLeiste ordnerName="Posteingang" absender="Anna" onVollbild={onVollbild} />));
        click(lesen.container.querySelector('[data-slot="mailbox-mobil-vollbild"]')!);
        expect(onVollbild).toHaveBeenCalledTimes(1);
        lesen.unmount();
    });
});
