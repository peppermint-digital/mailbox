import { describe, expect, it, vi } from 'vitest';
import { MailButton } from '../src/MailButton';
import { render } from './support/render';

/**
 * Der Postfach-Knopf der Kopfzeile (19.10.2026).
 *
 * Geprueft wird vor allem, was man bei einem Knopf leicht kaputtmacht, ohne
 * es zu merken: dass er die Gewohnheiten des Browsers behaelt. Ein Knopf, der
 * „in neuem Reiter oeffnen" verschluckt, aergert jeden Tag ein bisschen — und
 * niemand meldet es als Fehler.
 */
function knopf(props: Parameters<typeof MailButton>[0] = {}) {
    const { container, unmount } = render(<MailButton {...props} />);
    const element = container.querySelector('[data-slot="mail-button"]') as HTMLAnchorElement;

    return { element, container, unmount };
}

describe('MailButton', () => {
    it('fuehrt standardmaessig auf die Postfach-Seite', () => {
        const { element, unmount } = knopf();

        expect(element.getAttribute('href')).toBe('/mailbox');

        unmount();
    });

    it('zeigt ohne Zahl kein Abzeichen', () => {
        // Die Vorgabe. Eine Zahl kostet je Postfach eine Verbindung zum
        // Mailserver, auf JEDER Seite mit dieser Kopfzeile.
        const { container, unmount } = knopf();

        expect(container.querySelector('[data-slot="mail-button-count"]')).toBeNull();

        unmount();
    });

    it('zeigt eine Zahl, wenn das Produkt sie billig hat', () => {
        const { container, unmount } = knopf({ count: 7 });

        expect(container.querySelector('[data-slot="mail-button-count"]')?.textContent).toBe('7');

        unmount();
    });

    it('zeigt bei 0 kein Abzeichen', () => {
        // „0 ungelesen" ist keine Nachricht, sondern Rauschen.
        const { container, unmount } = knopf({ count: 0 });

        expect(container.querySelector('[data-slot="mail-button-count"]')).toBeNull();

        unmount();
    });

    it('kuerzt ab 100 auf 99+', () => {
        // Die dreistellige Zahl sprengt das Abzeichen, und ab da hilft die
        // genaue Zahl bei der Entscheidung nicht mehr.
        const { container, unmount } = knopf({ count: 128 });

        expect(container.querySelector('[data-slot="mail-button-count"]')?.textContent).toBe('99+');

        unmount();
    });

    it('nennt die Zahl auch fuer Hilfsmittel', () => {
        // Ein Abzeichen ohne Text ist fuer einen Screenreader ein Punkt.
        const { element, unmount } = knopf({ count: 3 });

        expect(element.getAttribute('aria-label')).toBe('Postfach, 3 ungelesen');

        unmount();
    });

    it('laesst den Router navigieren, statt die Seite neu zu laden', () => {
        const gegangen = vi.fn();
        const { element, unmount } = knopf({ onNavigate: gegangen });

        element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));

        expect(gegangen).toHaveBeenCalledWith('/mailbox');

        unmount();
    });

    it('laesst „in neuem Reiter oeffnen" in Ruhe', () => {
        /*
         * Mit Strg oder der Mitteltaste will jemand einen neuen Reiter. Das
         * abzufangen nimmt ihm eine Gewohnheit weg, die ueberall sonst
         * funktioniert — und es faellt nie als Fehler auf, nur als
         * „irgendwie komisch".
         */
        const gegangen = vi.fn();
        const { element, unmount } = knopf({ onNavigate: gegangen });

        element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ctrlKey: true }));
        element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 1 }));

        expect(gegangen).not.toHaveBeenCalled();

        unmount();
    });
});
