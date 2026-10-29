import { describe, expect, it, vi, beforeEach } from 'vitest';
import { act } from 'react';
import { MailboxSidePanel, MailboxSidePanelHandle, useMailboxSidePanel } from '../src/MailboxSidePanel';
import { render, textOf, click } from './support/render';

describe('MailboxSidePanel', () => {
    it('shows its heading and its content', () => {
        const { container, unmount } = render(
            <MailboxSidePanel title="Postfach-Assistent">
                <p>Inhalt</p>
            </MailboxSidePanel>,
        );

        expect(textOf(container)).toContain('Postfach-Assistent');
        expect(textOf(container)).toContain('Inhalt');
        unmount();
    });

    it('has no close button when there is nowhere to close to', () => {
        // A panel the product cannot reopen must not offer to disappear.
        const { container, unmount } = render(<MailboxSidePanel title="Kontakt">x</MailboxSidePanel>);

        expect(container.querySelectorAll('button').length).toBe(0);
        unmount();
    });

    it('closes when asked', () => {
        const zu = vi.fn();
        const { container, unmount } = render(
            <MailboxSidePanel title="Kontakt" onClose={zu}>
                x
            </MailboxSidePanel>,
        );

        click(container.querySelector('button[aria-label="Ausblenden"]')!);

        expect(zu).toHaveBeenCalled();
        unmount();
    });
});

describe('MailboxSidePanelHandle', () => {
    it('says what it brings back', () => {
        // "Einblenden" alone says nothing about what appears.
        const { container, unmount } = render(<MailboxSidePanelHandle label="AI-Brain-Chat" onClick={() => {}} />);

        expect(textOf(container)).toBe('AI-Brain-Chat');
        expect(container.querySelector('button')!.getAttribute('aria-label')).toBe('AI-Brain-Chat einblenden');
        unmount();
    });
});

describe('useMailboxSidePanel', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.restoreAllMocks();
    });

    function mount(key: string, standard?: boolean) {
        const ref: { current: ReturnType<typeof useMailboxSidePanel> } = { current: null as never };
        function Probe() {
            ref.current = useMailboxSidePanel(key, standard);
            return null;
        }
        const { unmount } = render(<Probe />);
        return { ref, unmount };
    }

    it('starts open unless told otherwise', () => {
        const { ref, unmount } = mount('chat');
        expect(ref.current.sichtbar).toBe(true);
        unmount();
    });

    it('remembers being closed across a reload', () => {
        const { ref, unmount } = mount('chat');
        act(() => ref.current.verbergen());
        unmount();

        const zweiter = mount('chat');
        expect(zweiter.ref.current.sichtbar).toBe(false);
        zweiter.unmount();
    });

    it('sagt, ob der offene Zustand eine Wahl ist oder nur die Vorgabe', () => {
        /*
         * Der Unterschied klang nach Haarspalterei und hat am 29.10.2026 die
         * Handy-Ansicht gekostet: Der Assistent steht per Vorgabe offen, und
         * die Flaeche legte ihn deshalb ab dem ersten Aufruf ueber die
         * Mailliste. Man sah den Chat statt seiner Post.
         *
         * Beim ersten Besuch liegt nichts im Speicher — also ist offen die
         * Vorgabe und keine Wahl.
         */
        const { ref, unmount } = mount('neu-hier');

        expect(ref.current.sichtbar).toBe(true);
        expect(ref.current.gewaehlt).toBe(false);

        unmount();
    });

    it('ist eine Wahl, sobald jemand schaltet — und bleibt es nach dem Neuladen', () => {
        const erster = mount('gewaehlt-test');
        act(() => erster.ref.current.verbergen());
        expect(erster.ref.current.gewaehlt).toBe(true);
        act(() => erster.ref.current.zeigen());
        expect(erster.ref.current.gewaehlt).toBe(true);
        expect(erster.ref.current.sichtbar).toBe(true);
        erster.unmount();

        // Nach dem Neuladen: offen UND gewaehlt — ab hier darf es abdecken.
        const zweiter = mount('gewaehlt-test');
        expect(zweiter.ref.current.sichtbar).toBe(true);
        expect(zweiter.ref.current.gewaehlt).toBe(true);
        zweiter.unmount();
    });

    it('keeps two panels apart', () => {
        // Otherwise closing the assistant also closes the contact rail.
        const a = mount('chat');
        act(() => a.ref.current.verbergen());
        a.unmount();

        const b = mount('kontakt');
        expect(b.ref.current.sichtbar).toBe(true);
        b.unmount();
    });

    it('survives a browser that refuses storage entirely', () => {
        // Some browsers throw on ACCESS in a private window, not just on write.
        // An unguarded read takes the whole page down — and it takes a browser
        // nobody tests in to find out.
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('denied');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('denied');
        });

        const { ref, unmount } = mount('chat', false);
        expect(ref.current.sichtbar).toBe(false);

        act(() => ref.current.zeigen());
        expect(ref.current.sichtbar).toBe(true);
        unmount();
    });
});
