import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { MailAddressBook, type AddressBookEntry, type MailAddressBookLabels } from '../src/MailAddressBook';
import { render, textOf } from './support/render';

/**
 * Das Adressbuch (22.09.2026).
 *
 * Geprueft wird, was still falsch sein kann: Wird dieselbe Person zweimal
 * gezeigt, weil sie in zwei Vorraeten liegt? Wird der teure Weg schon beim
 * ersten Buchstaben gegangen? Und sagt die leere Flaeche, ob gerade gesucht
 * wird oder ob nichts da ist — oder sieht beides gleich aus?
 */
const texte: MailAddressBookLabels = {
    heading: 'Adressbuch',
    description: 'Kollegen und Kunden.',
    back: 'Zurück',
    searchPlaceholder: 'Name oder E-Mail suchen…',
    copy: 'E-Mail-Adresse kopieren',
    copied: 'E-Mail-Adresse kopiert',
    copyFailed: 'Kopieren nicht möglich',
    compose: 'E-Mail',
    searching: 'Suche läuft…',
    noResults: (q) => `Keine Treffer für „${q}".`,
    prompt: 'Tippe, um im Adressbuch zu suchen.',
    source: (s) => (s === 'adressbuch' ? 'Adressbuch' : s === 'colleague' ? 'Kollege' : 'Verlauf'),
};

const eintrag = (email: string, rest: Partial<AddressBookEntry> = {}): AddressBookEntry => ({ email, name: email.split('@')[0], ...rest });

function tippe(container: HTMLElement, wert: string): void {
    const feld = container.querySelector<HTMLInputElement>('input')!;
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')!.set!;

    act(() => {
        setter.call(feld, wert);
        feld.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

describe('MailAddressBook', () => {
    it('zeigt den lokalen Vorrat ohne Zutun', async () => {
        const { container, unmount } = render(
            <MailAddressBook labels={texte} onCompose={() => {}} source={{ local: async () => [eintrag('anna@example.test')] }} />,
        );

        await act(async () => { await Promise.resolve(); });

        expect(textOf(container)).toContain('anna@example.test');
        unmount();
    });

    it('zeigt eine Person nur einmal, auch wenn sie in beiden Vorraeten liegt', async () => {
        // Zweimal dieselbe Adresse heisst: Der Leser muss selbst herausfinden,
        // dass es eine Person ist. Der gepflegte Eintrag gewinnt, weil er den
        // Namen traegt, den jemand eingetippt hat.
        const { container, unmount } = render(
            <MailAddressBook
                labels={texte}
                onCompose={() => {}}
                source={{
                    local: async () => [eintrag('anna@example.test', { name: 'Anna Meier', source: 'adressbuch' })],
                    external: async () => [eintrag('ANNA@example.test', { name: 'anna', source: 'crm' })],
                }}
            />,
        );

        await act(async () => { await Promise.resolve(); });
        tippe(container, 'anna');
        await act(async () => { await new Promise((r) => setTimeout(r, 400)); });

        expect(container.querySelectorAll('[data-slot="mail-address-book-row"]').length).toBe(1);
        expect(textOf(container)).toContain('Anna Meier');
        unmount();
    });

    it('fragt den teuren Weg erst ab der Mindestlaenge', async () => {
        // Ein Buchstabe schraenkt nichts ein. Wer bei jedem Anschlag in fremde
        // Systeme greift, erzeugt Last fuer ein Ergebnis, das niemand liest.
        const extern = vi.fn(async () => []);
        const { container, unmount } = render(<MailAddressBook labels={texte} onCompose={() => {}} source={{ external: extern }} />);

        tippe(container, 'a');
        await act(async () => { await new Promise((r) => setTimeout(r, 400)); });
        expect(extern).not.toHaveBeenCalled();

        tippe(container, 'an');
        await act(async () => { await new Promise((r) => setTimeout(r, 400)); });
        expect(extern).toHaveBeenCalledOnce();
        unmount();
    });

    it('fragt nicht bei jedem Anschlag, sondern wenn das Tippen aufhoert', async () => {
        const extern = vi.fn(async () => []);
        const { container, unmount } = render(<MailAddressBook labels={texte} onCompose={() => {}} source={{ external: extern }} />);

        tippe(container, 'an');
        tippe(container, 'ann');
        tippe(container, 'anna');
        await act(async () => { await new Promise((r) => setTimeout(r, 400)); });

        expect(extern).toHaveBeenCalledOnce();
        expect(extern.mock.calls[0][0]).toBe('anna');
        unmount();
    });

    it('unterscheidet „suche laeuft" von „nichts gefunden"', async () => {
        // Sahen beide gleich aus, wartete man auf ein Ergebnis, das schon da
        // war — oder hielt eine laufende Suche fuer ein leeres Adressbuch.
        const { container, unmount } = render(
            <MailAddressBook labels={texte} onCompose={() => {}} source={{ external: async () => [] }} />,
        );

        expect(textOf(container)).toContain('Tippe, um im Adressbuch zu suchen.');

        tippe(container, 'anna');
        await act(async () => { await Promise.resolve(); });
        expect(textOf(container)).toContain('Suche läuft…');

        await act(async () => { await new Promise((r) => setTimeout(r, 400)); });
        expect(textOf(container)).toContain('Keine Treffer für „anna".');
        unmount();
    });

    it('kommt ohne externe Quelle aus', async () => {
        // Ein Produkt, das nur seinen eigenen Bestand hat, soll benutzbar sein
        // und nicht auf eine Antwort warten, die niemand schickt.
        const { container, unmount } = render(
            <MailAddressBook labels={texte} onCompose={() => {}} source={{ local: async () => [eintrag('anna@example.test')] }} />,
        );

        await act(async () => { await Promise.resolve(); });
        tippe(container, 'anna');
        await act(async () => { await new Promise((r) => setTimeout(r, 400)); });

        expect(textOf(container)).toContain('anna@example.test');
        expect(textOf(container)).not.toContain('Suche läuft…');
        unmount();
    });

    it('bleibt benutzbar, wenn der lokale Vorrat nicht antwortet', async () => {
        // Ein Ausfall auf der einen Seite darf die andere nicht mitnehmen.
        const { container, unmount } = render(
            <MailAddressBook
                labels={texte}
                onCompose={() => {}}
                source={{
                    local: async () => { throw new Error('weg'); },
                    external: async () => [eintrag('kunde@example.test')],
                }}
            />,
        );

        await act(async () => { await Promise.resolve(); });
        tippe(container, 'kunde');
        await act(async () => { await new Promise((r) => setTimeout(r, 400)); });

        expect(textOf(container)).toContain('kunde@example.test');
        unmount();
    });

    it('reicht den gewaehlten Eintrag zum Verfassen durch', async () => {
        const verfasse = vi.fn();
        const { container, unmount } = render(
            <MailAddressBook labels={texte} onCompose={verfasse} source={{ local: async () => [eintrag('anna@example.test')] }} />,
        );

        await act(async () => { await Promise.resolve(); });
        const knopf = [...container.querySelectorAll('button')].find((b) => b.textContent?.includes('E-Mail'))!;
        act(() => { knopf.dispatchEvent(new MouseEvent('click', { bubbles: true })); });

        expect(verfasse).toHaveBeenCalledWith(expect.objectContaining({ email: 'anna@example.test' }));
        unmount();
    });
});
