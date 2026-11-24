import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { PostfachAnlegen, PostfachNeuVerbinden, postfachVerbindenAdresse } from '../src/PostfachAnlegen';
import { click, render, textOf } from './support/render';

/**
 * Die Maske zum Anlegen eines Postfachs (24.11.2026, #10189).
 *
 * Die Regeln stehen auf dem Server (`LegtKontenAn`). Hier wird geprueft, dass
 * die Maske ihn richtig anspricht und seine Antworten zeigt — vor allem die
 * Meldung eines gescheiterten Verbindungstests, die sonst stumm verschwindet.
 */

function antwort(status: number, daten: unknown): Response {
    return new Response(JSON.stringify(daten), { status, headers: { 'Content-Type': 'application/json' } });
}

function tippe(feld: HTMLInputElement, wert: string): void {
    const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(feld), 'value')?.set;
    act(() => {
        setter?.call(feld, wert);
        feld.dispatchEvent(new Event('input', { bubbles: true }));
    });
}

function knopf(container: HTMLElement, text: string): HTMLButtonElement {
    const treffer = [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === text);
    if (!treffer) {
        throw new Error(`Kein Knopf „${text}" in: ${textOf(container)} ${document.body.textContent}`);
    }

    return treffer as HTMLButtonElement;
}

function eingabe(name: string): HTMLInputElement {
    const feld = document.querySelector<HTMLInputElement>(`input[name="${name}"]`);
    if (!feld) {
        throw new Error(`Kein Feld ${name}`);
    }

    return feld;
}

function zumKennwortFormular(container: HTMLElement): void {
    click(knopf(container, 'Postfach hinzufügen'));
    click(knopf(container, 'Mit Kennwort (IMAP/JMAP)'));
    tippe(eingabe('name'), 'Info');
    tippe(eingabe('email'), 'info@beispiel.de');
    tippe(eingabe('host'), 'imap.beispiel.de');
    tippe(eingabe('password'), 'geheim');
}

describe('PostfachAnlegen', () => {
    it('bietet nur die eingerichteten Anbieter an und geht mit Name zum Anbieter', () => {
        const navigieren = vi.fn();
        const { container, unmount } = render(<PostfachAnlegen basis="/settings/email-accounts" anbieter={['microsoft']} navigieren={navigieren} />);

        click(knopf(container, 'Postfach hinzufügen'));
        expect(document.body.textContent).toContain('Mit Microsoft 365 anmelden');
        expect(document.body.textContent).not.toContain('Mit Google anmelden');

        tippe(eingabe('name'), 'Office');
        click(knopf(container, 'Mit Microsoft 365 anmelden'));

        expect(navigieren).toHaveBeenCalledWith('/settings/email-accounts/oauth/microsoft?name=Office');
        unmount();
    });

    it('zeigt das Gruppenpostfach nur, wer teilen darf', () => {
        const { container, unmount } = render(<PostfachAnlegen basis="/b" />);
        click(knopf(container, 'Postfach hinzufügen'));
        expect(document.body.textContent).not.toContain('Gruppenpostfach');
        unmount();

        const zweite = render(<PostfachAnlegen basis="/b" darfTeilen />);
        click(knopf(zweite.container, 'Postfach hinzufügen'));
        expect(document.body.textContent).toContain('Gruppenpostfach');
        zweite.unmount();
    });

    it('zeigt den Grund, wenn der Server die Verbindung ablehnt, und meldet nichts als angelegt', async () => {
        const holen = vi.fn(async () => antwort(422, { errors: { verbindung: ['Posteingang: Authentication failed.'] } }));
        const angelegt = vi.fn();
        const { container, unmount } = render(
            <PostfachAnlegen basis="/settings/email-accounts" fetch={holen as unknown as typeof fetch} csrfToken={() => 'tok'} onAngelegt={angelegt} />,
        );

        zumKennwortFormular(container);
        await act(async () => {
            click(knopf(container, 'Anlegen'));
        });

        expect(document.body.textContent).toContain('Keine Verbindung: Posteingang: Authentication failed.');
        expect(angelegt).not.toHaveBeenCalled();

        const [adresse, optionen] = holen.mock.calls[0] as unknown as [string, RequestInit];
        expect(adresse).toBe('/settings/email-accounts');
        expect(JSON.parse(String(optionen.body))).toMatchObject({
            email: 'info@beispiel.de',
            port: 993,
            username: null,
            smtp_host: null,
            geteilt: false,
            leser: [],
        });
        unmount();
    });

    it('testet gegen {basis}/pruefen und meldet das Angelegte weiter', async () => {
        const holen = vi.fn(async (adresse: string) =>
            adresse.endsWith('/pruefen')
                ? antwort(200, { ok: true, message: 'Verbindung steht, 5 Ordner gefunden.' })
                : antwort(201, { account: { id: 31, email: 'info@beispiel.de', label: 'Info' } }),
        );
        const angelegt = vi.fn();
        const { container, unmount } = render(
            <PostfachAnlegen basis="/settings/email-accounts" fetch={holen as unknown as typeof fetch} csrfToken={() => 'tok'} onAngelegt={angelegt} />,
        );

        zumKennwortFormular(container);
        await act(async () => {
            click(knopf(container, 'Verbindung testen'));
        });
        expect(holen.mock.calls[0][0]).toBe('/settings/email-accounts/pruefen');
        // Der Satz des Servers traegt sein Urteil schon — kein zweites davor
        // (im DevStack: „Verbindung steht: Verbindung steht, 1 Ordner gefunden.").
        expect(document.querySelector('[role="status"]')?.textContent).toBe('Verbindung steht, 5 Ordner gefunden.');

        await act(async () => {
            click(knopf(container, 'Anlegen'));
        });
        expect(angelegt).toHaveBeenCalledWith({ id: 31, email: 'info@beispiel.de', label: 'Info' });
        unmount();
    });
});

it('laesst ein altes "Verbindung steht" nicht neben einem neuen Fehlschlag stehen', async () => {
    /*
     * Im DevStack gesehen (24.11.2026): Ein zweiter Test brach ab, die Maske
     * meldete den Fehler — und zeigte darueber weiter das gruene Ergebnis des
     * ersten. Wer nur den gruenen Satz liest, haelt die Pruefung fuer bestanden.
     */
    let runde = 0;
    const holen = vi.fn(async () => {
        runde += 1;
        if (runde === 1) {
            return antwort(200, { ok: true, message: 'Verbindung steht, 5 Ordner gefunden.' });
        }
        throw new Error('abgebrochen');
    });
    const { container, unmount } = render(<PostfachAnlegen basis="/b" fetch={holen as unknown as typeof fetch} csrfToken={() => 'tok'} />);

    zumKennwortFormular(container);
    await act(async () => {
        click(knopf(container, 'Verbindung testen'));
    });
    expect(document.querySelector('[role="status"]')?.textContent).toBe('Verbindung steht, 5 Ordner gefunden.');

    await act(async () => {
        click(knopf(container, 'Verbindung testen'));
    });
    expect(document.body.textContent).toContain('Das Postfach konnte nicht angelegt werden.');
    expect(document.querySelector('[role="status"]')).toBeNull();
    unmount();
});

describe('PostfachNeuVerbinden', () => {
    it('traegt die Kennung des Postfachs mit', () => {
        const navigieren = vi.fn();
        const { container, unmount } = render(<PostfachNeuVerbinden basis="/settings/email-accounts" konto={9} anbieter="microsoft" navigieren={navigieren} />);

        click(knopf(container, 'Neu verbinden'));

        expect(navigieren).toHaveBeenCalledWith('/settings/email-accounts/oauth/microsoft?konto=9');
        unmount();
    });

    it('baut die Adresse ohne leere Angaben', () => {
        expect(postfachVerbindenAdresse('/b/', 'google', { name: '', geteilt: true })).toBe('/b/oauth/google?geteilt=1');
    });
});
