import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { RichTextEditorProps } from '../src/RichTextEditor';
import { SignaturPflege } from '../src/SignaturPflege';
import { click, render, textOf } from './support/render';

/**
 * Die Pflegemaske der eigenen Signaturen (24.11.2026, #10195).
 *
 * Die Regeln stehen auf dem Server (`PflegtSignaturen`); hier wird geprueft,
 * dass die Maske sie richtig anspricht und seine Antworten zeigt — vor allem
 * die Pflichtfeld-Meldungen, die sonst stumm verschwinden.
 */

const EditorAttrappe = ({ value, onChange }: RichTextEditorProps) => (
    <textarea data-testid="rumpf" value={value} onChange={(e) => onChange?.(e.target.value)} />
);

function antwort(status: number, daten: unknown): Response {
    return new Response(JSON.stringify(daten), { status, headers: { 'Content-Type': 'application/json' } });
}

const liste = {
    signatures: [
        { id: 1, name: 'Standard', body_html: '<p>Gruss</p>', is_default: true, mail_account_id: null },
        { id: 2, name: 'Info', body_html: '<p>Team</p>', is_default: false, mail_account_id: 7 },
    ],
    accounts: [{ id: 7, email: 'info@beispiel.de', label: 'Info-Postfach' }],
};

function tippe(feld: HTMLInputElement | HTMLTextAreaElement, wert: string): void {
    const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(feld), 'value')?.set;
    act(() => {
        setter?.call(feld, wert);
        feld.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

async function zeige(holen: typeof fetch) {
    let ergebnis!: ReturnType<typeof render>;
    await act(async () => {
        ergebnis = render(<SignaturPflege basis="/emails/signaturen" fetch={holen} csrfToken={() => 'tok'} editor={EditorAttrappe} />);
    });

    return ergebnis;
}

function knopf(container: HTMLElement, text: string): HTMLButtonElement {
    const treffer = [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === text);
    if (!treffer) {
        throw new Error(`Kein Knopf „${text}" in: ${textOf(container)}`);
    }

    return treffer as HTMLButtonElement;
}

describe('SignaturPflege', () => {
    it('zeigt die eigenen Signaturen mit Postfach und Vorgabe', async () => {
        const holen = vi.fn(async () => antwort(200, liste));
        const { container, unmount } = await zeige(holen as unknown as typeof fetch);

        const text = textOf(container);
        expect(text).toContain('Standard');
        expect(text).toContain('Vorgabe');
        expect(text).toContain('Alle Postfächer');
        expect(text).toContain('Info-Postfach');
        expect(holen).toHaveBeenCalledWith('/emails/signaturen', expect.objectContaining({ credentials: 'same-origin' }));
        unmount();
    });

    it('legt eine neue Signatur an und laedt danach neu', async () => {
        const holen = vi
            .fn()
            .mockResolvedValueOnce(antwort(200, { signatures: [], accounts: [] }))
            .mockResolvedValueOnce(antwort(201, { signature: {} }))
            .mockResolvedValueOnce(antwort(200, liste));
        const { container, unmount } = await zeige(holen as unknown as typeof fetch);

        click(knopf(container, 'Neue Signatur'));
        tippe(container.querySelector('#signatur-name') as HTMLInputElement, 'Neu');
        tippe(container.querySelector('[data-testid="rumpf"]') as HTMLTextAreaElement, '<p>Hallo</p>');

        await act(async () => {
            (container.querySelector('form') as HTMLFormElement).requestSubmit();
        });

        const [url, optionen] = holen.mock.calls[1];
        expect(url).toBe('/emails/signaturen');
        expect(optionen.method).toBe('POST');
        expect(optionen.headers['X-CSRF-TOKEN']).toBe('tok');
        expect(JSON.parse(optionen.body)).toEqual({ name: 'Neu', body_html: '<p>Hallo</p>', is_default: false, mail_account_id: null });
        expect(holen).toHaveBeenCalledTimes(3);
        expect(container.querySelector('form')).toBeNull();
        unmount();
    });

    it('zeigt die Meldung des Servers am Feld und behaelt den Entwurf', async () => {
        const holen = vi
            .fn()
            .mockResolvedValueOnce(antwort(200, { signatures: [], accounts: [] }))
            .mockResolvedValueOnce(antwort(422, { errors: { body_html: ['Die Signatur ist leer.'] } }));
        const { container, unmount } = await zeige(holen as unknown as typeof fetch);

        click(knopf(container, 'Neue Signatur'));
        await act(async () => {
            (container.querySelector('form') as HTMLFormElement).requestSubmit();
        });

        expect(textOf(container)).toContain('Die Signatur ist leer.');
        expect(container.querySelector('form')).not.toBeNull();
        unmount();
    });

    it('loescht erst nach der Rueckfrage', async () => {
        const holen = vi
            .fn()
            .mockResolvedValueOnce(antwort(200, liste))
            .mockResolvedValueOnce(antwort(200, { deleted: true }))
            .mockResolvedValueOnce(antwort(200, { signatures: [], accounts: [] }));
        const { container, unmount } = await zeige(holen as unknown as typeof fetch);

        click(knopf(container, 'Löschen'));
        expect(holen).toHaveBeenCalledTimes(1);

        const imDialog = [...document.querySelectorAll('[role="dialog"] button')].find((b) => b.textContent?.trim() === 'Löschen');
        await act(async () => {
            imDialog?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });

        expect(holen.mock.calls[1][0]).toBe('/emails/signaturen/1');
        expect(holen.mock.calls[1][1].method).toBe('DELETE');
        unmount();
    });
});
