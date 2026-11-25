import { describe, expect, it } from 'vitest';
import EmailBodyViewer from '../src/EmailBodyViewer';
import { click, render, textOf } from './support/render';

const labels = {
    empty: 'Kein Inhalt',
    remoteBlocked: 'Externe Bilder wurden zum Schutz vor Tracking blockiert.',
    loadImages: 'Bilder laden',
    frameTitle: 'Nachrichteninhalt',
};

describe('EmailBodyViewer', () => {
    it('says the product word when there is no body at all', () => {
        const { container, unmount } = render(<EmailBodyViewer labels={labels} />);

        expect(textOf(container)).toContain('Kein Inhalt');
        unmount();
    });

    it('shows a plain-text body without an iframe', () => {
        const { container, unmount } = render(<EmailBodyViewer labels={labels} bodyText="Hallo Welt" />);

        expect(textOf(container)).toContain('Hallo Welt');
        expect(container.querySelector('iframe')).toBeNull();
        unmount();
    });

    it('puts an HTML body behind an iframe', () => {
        // The isolation is the point: a mail is foreign HTML, and it does not
        // get to reach into the page around it.
        const { container, unmount } = render(<EmailBodyViewer labels={labels} bodyHtml="<p>Hallo</p>" />);

        const frame = container.querySelector('iframe');
        expect(frame).not.toBeNull();
        expect(frame?.getAttribute('title')).toBe('Nachrichteninhalt');
        unmount();
    });

    it('warns about blocked remote content and offers to release it', () => {
        const { container, unmount } = render(
            <EmailBodyViewer labels={labels} bodyHtml='<img src="https://tracker.example/x.gif">' blockRemoteImages />,
        );

        expect(textOf(container)).toContain('Externe Bilder wurden zum Schutz vor Tracking blockiert.');
        expect(textOf(container)).toContain('Bilder laden');
        unmount();
    });

    it('drops the warning once the images are released', () => {
        const { container, unmount } = render(
            <EmailBodyViewer labels={labels} bodyHtml='<img src="https://tracker.example/x.gif">' blockRemoteImages />,
        );

        const button = [...container.querySelectorAll('button')].find((b) => b.textContent?.includes('Bilder laden'));
        expect(button).toBeDefined();

        click(button as HTMLButtonElement);

        expect(textOf(container)).not.toContain('Externe Bilder wurden zum Schutz vor Tracking blockiert.');
        unmount();
    });

    it('releases the images in every viewer of the SAME body, and only there', () => {
        // Seit dem 25.11.2026: Das Vollbild ist ein zweiter Betrachter ueber
        // derselben Mail. Freigegeben in einem, freigegeben im anderen — eine
        // andere Mail bleibt zu.
        const rumpf = '<img src="https://tracker.example/geteilt.gif">';
        const leseansicht = render(<EmailBodyViewer labels={labels} bodyHtml={rumpf} blockRemoteImages />);
        const vollbild = render(<EmailBodyViewer labels={labels} bodyHtml={rumpf} blockRemoteImages />);
        const andere = render(<EmailBodyViewer labels={labels} bodyHtml='<img src="https://tracker.example/andere.gif">' blockRemoteImages />);

        const button = [...vollbild.container.querySelectorAll('button')].find((b) => b.textContent?.includes('Bilder laden'));
        click(button as HTMLButtonElement);

        expect(textOf(leseansicht.container)).not.toContain('Externe Bilder');
        expect(textOf(vollbild.container)).not.toContain('Externe Bilder');
        expect(textOf(andere.container)).toContain('Externe Bilder');

        // Und ein Betrachter, der erst DANACH aufgeht, kennt die Freigabe auch.
        const spaeter = render(<EmailBodyViewer labels={labels} bodyHtml={rumpf} blockRemoteImages />);
        expect(textOf(spaeter.container)).not.toContain('Externe Bilder');

        [leseansicht, vollbild, andere, spaeter].forEach((r) => r.unmount());
    });

    it('does not warn when nothing remote is in the body', () => {
        // A notice about blocked images above a mail that has none reads as a
        // warning about something that did not happen.
        const { container, unmount } = render(<EmailBodyViewer labels={labels} bodyHtml="<p>Nur Text</p>" blockRemoteImages />);

        expect(textOf(container)).not.toContain('Externe Bilder');
        unmount();
    });
});
