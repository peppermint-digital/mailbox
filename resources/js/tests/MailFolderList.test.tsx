import { describe, expect, it, vi } from 'vitest';
import MailFolderList, { type MailFolder } from '../src/MailFolderList';
import { click, render, textOf } from './support/render';

const labels = { heading: 'Ordner', createFolder: 'Neuer Ordner' };

const folders: MailFolder[] = [
    { name: 'Posteingang', path: 'INBOX' },
    { name: 'Gesendet', path: 'INBOX.Sent', flags: ['\\Sent'] },
    { name: '2026', path: 'Projekte/2026' },
];

describe('MailFolderList', () => {
    it('lists the folders it is handed', () => {
        const { container, unmount } = render(<MailFolderList folders={folders} labels={labels} onSelect={() => {}} />);

        expect(container.querySelectorAll('[data-slot="mail-folder"]')).toHaveLength(3);
        expect(textOf(container)).toContain('Posteingang');
        unmount();
    });

    it('gives INBOX its own icon — it is the folder people look for first', () => {
        const { container, unmount } = render(<MailFolderList folders={folders} labels={labels} onSelect={() => {}} />);

        const buttons = [...container.querySelectorAll('[data-slot="mail-folder"]')];
        // The inbox icon differs from the generic folder icon; compare the markup.
        expect(buttons[0].innerHTML).not.toBe(buttons[2].innerHTML.replace('2026', 'Posteingang'));
        expect(buttons[0].querySelector('svg')?.outerHTML).not.toBe(buttons[2].querySelector('svg')?.outerHTML);
        unmount();
    });

    it('recognises INBOX whatever its case', () => {
        const { container, unmount } = render(
            <MailFolderList folders={[{ name: 'Posteingang', path: 'inbox' }, { name: 'X', path: 'X' }]} labels={labels} onSelect={() => {}} />,
        );

        const buttons = [...container.querySelectorAll('[data-slot="mail-folder"]')];
        expect(buttons[0].querySelector('svg')?.outerHTML).not.toBe(buttons[1].querySelector('svg')?.outerHTML);
        unmount();
    });

    it('marks the folder currently shown', () => {
        const { container, unmount } = render(
            <MailFolderList folders={folders} labels={labels} selectedPath="INBOX.Sent" onSelect={() => {}} />,
        );

        const selected = container.querySelectorAll('[data-slot="mail-folder"][data-selected]');
        expect(selected).toHaveLength(1);
        expect(selected[0].textContent).toContain('Gesendet');
        unmount();
    });

    it('reports the path, not the name — the path is what addresses a folder', () => {
        const onSelect = vi.fn();
        const { container, unmount } = render(<MailFolderList folders={folders} labels={labels} onSelect={onSelect} />);

        click(container.querySelectorAll('[data-slot="mail-folder"]')[1]);

        expect(onSelect).toHaveBeenCalledWith('INBOX.Sent');
        unmount();
    });

    it('hides the create button when the product does not offer it', () => {
        const { container, unmount } = render(<MailFolderList folders={folders} labels={labels} onSelect={() => {}} />);

        expect(container.querySelector('[title="Neuer Ordner"]')).toBeNull();
        unmount();
    });

    it('shows it when the product does', () => {
        const onCreate = vi.fn();
        const { container, unmount } = render(
            <MailFolderList folders={folders} labels={labels} onSelect={() => {}} onCreate={onCreate} />,
        );

        click(container.querySelector('[title="Neuer Ordner"]') as HTMLElement);

        expect(onCreate).toHaveBeenCalledOnce();
        unmount();
    });

    it('shows placeholders instead of an empty list while loading', () => {
        const { container, unmount } = render(
            <MailFolderList folders={[]} labels={labels} onSelect={() => {}} loading loadingPlaceholder={<span>lädt</span>} />,
        );

        expect(textOf(container)).toContain('lädt');
        expect(container.querySelector('[data-slot="mail-folder-list"]')).toBeNull();
        unmount();
    });
});
