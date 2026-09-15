import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { act } from 'react';
import { MailboxChat, type MailboxChatSource, type MailboxChatState } from '../src/MailboxChat';
import { httpMailboxChatSource } from '../src/mailboxChatSource';
import { render, textOf, click } from './support/render';

const konto = { id: 7, email: 'buero@example.test' };

const zustand = (teile: Partial<MailboxChatState> = {}): MailboxChatState => ({
    messages: [],
    status: null,
    activity: [],
    ...teile,
});

const nachricht = (id: number, role: string, content: string) => ({ id, role, content, is_pending: false, created_at: '' });

function quelle(overrides: Partial<MailboxChatSource> = {}): MailboxChatSource {
    return {
        load: vi.fn(async () => zustand()),
        send: vi.fn(async () => null),
        ...overrides,
    };
}

/** Render and let the first load settle. */
async function zeige(source: MailboxChatSource, props: Record<string, unknown> = {}) {
    let ergebnis!: ReturnType<typeof render>;
    await act(async () => {
        ergebnis = render(<MailboxChat account={konto} source={source} {...props} />);
    });
    return ergebnis;
}

async function tippen(container: HTMLElement, text: string) {
    const feld = container.querySelector('input') as HTMLInputElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!;
    await act(async () => {
        setter.call(feld, text);
        feld.dispatchEvent(new Event('input', { bubbles: true }));
    });
    return feld;
}

async function absenden(container: HTMLElement) {
    const form = container.querySelector('form') as HTMLFormElement;
    await act(async () => {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
}

describe('MailboxChat', () => {
    it('shows the conversation it was handed', async () => {
        const { container, unmount } = await zeige(quelle({ load: vi.fn(async () => zustand({ messages: [nachricht(1, 'assistant', 'Zwei ungelesene.')] })) }));

        expect(textOf(container)).toContain('Zwei ungelesene.');
        unmount();
    });

    it('keeps the conversation when a poll fails', async () => {
        // The polling runs every few seconds. One dropped request while a lift
        // passes must not blank the thread — and a blank thread reads as "the
        // agent forgot everything", not as "the network hiccuped".
        const antworten = [zustand({ messages: [nachricht(1, 'assistant', 'Zwei ungelesene.')] }), null];
        let ruf = 0;
        const source = quelle({ load: vi.fn(async () => antworten[ruf++] ?? null) });

        const { container, unmount } = await zeige(source);
        expect(textOf(container)).toContain('Zwei ungelesene.');

        // The failing second read.
        await act(async () => {
            await source.load(konto.id);
        });

        expect(textOf(container)).toContain('Zwei ungelesene.');
        unmount();
    });

    it('names the status in words, not in codes', async () => {
        // `needs_input` is the case that matters: the agent waits for an answer
        // and does nothing on its own. Whoever cannot see that waits too.
        const { container, unmount } = await zeige(quelle({ load: vi.fn(async () => zustand({ status: 'needs_input' })) }));

        expect(textOf(container)).toContain('Wartet auf deine Antwort');
        unmount();
    });

    it('shows an unknown status as it comes rather than hiding it', async () => {
        const { container, unmount } = await zeige(quelle({ load: vi.fn(async () => zustand({ status: 'irgendwas_neues' })) }));

        expect(textOf(container)).toContain('irgendwas_neues');
        unmount();
    });

    it('carries the server reason through when a send fails', async () => {
        // A bare "sending failed" left one bug undiagnosable for a week.
        const source = quelle({ send: vi.fn(async () => 'Senden fehlgeschlagen: Kein Token') });
        const { container, unmount } = await zeige(source);

        await tippen(container, 'Was liegt an?');
        await absenden(container);

        expect(textOf(container)).toContain('Kein Token');
        unmount();
    });

    it('has no microphone when the product brought no speech', async () => {
        // A button that does nothing is worse than a missing one.
        const { container, unmount } = await zeige(quelle());

        expect(container.querySelectorAll('button[title*="diktieren"]').length).toBe(0);
        unmount();
    });

    it('lets an extra control write into the input field', async () => {
        const { container, unmount } = await zeige(quelle(), {
            controls: ({ insert }: { insert: (t: string) => void }) => (
                <button type="button" title="Einfuegen" onClick={() => insert('diktiert')}>
                    M
                </button>
            ),
        });

        await tippen(container, 'Bereits');
        await act(async () => {
            click(container.querySelector('button[title="Einfuegen"]')!);
        });

        expect((container.querySelector('input') as HTMLInputElement).value).toBe('Bereits diktiert');
        unmount();
    });

    it('forgets the previous mailbox when the account changes', async () => {
        // Otherwise the messages of one mailbox stand under the address of the
        // next for a moment — and that moment is enough to answer the wrong one.
        const source = quelle({ load: vi.fn(async () => zustand({ messages: [nachricht(1, 'assistant', 'Aus Büro.')] })) });
        const { container, rerender, unmount } = await zeige(source);

        expect(textOf(container)).toContain('Aus Büro.');

        const stumm: MailboxChatSource = { load: vi.fn(async () => new Promise<null>(() => null)), send: vi.fn(async () => null) };
        await act(async () => {
            rerender(<MailboxChat account={{ id: 9, email: 'privat@example.test' }} source={stumm} />);
        });

        expect(textOf(container)).not.toContain('Aus Büro.');
        unmount();
    });
});

describe('MailboxChat polling', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('asks again faster while the agent works', async () => {
        // Two seconds while running, eight while idle: a working agent produces
        // new steps constantly, an idle one produces nothing and every request
        // is waste.
        const source = quelle({ load: vi.fn(async () => zustand({ status: 'running' })) });

        await act(async () => {
            render(<MailboxChat account={konto} source={source} />);
        });
        expect(source.load).toHaveBeenCalledTimes(1);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(2100);
        });
        expect(source.load).toHaveBeenCalledTimes(2);
    });

    it('leaves an idle agent alone in between', async () => {
        const source = quelle({ load: vi.fn(async () => zustand({ status: 'done' })) });

        await act(async () => {
            render(<MailboxChat account={konto} source={source} />);
        });

        await act(async () => {
            await vi.advanceTimersByTimeAsync(2100);
        });
        expect(source.load).toHaveBeenCalledTimes(1);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(6100);
        });
        expect(source.load).toHaveBeenCalledTimes(2);
    });

    it('stops asking once it is gone', async () => {
        // A component polling into the void keeps a whole tab busy.
        const source = quelle({ load: vi.fn(async () => zustand({ status: 'running' })) });

        let ergebnis!: ReturnType<typeof render>;
        await act(async () => {
            ergebnis = render(<MailboxChat account={konto} source={source} />);
        });

        await act(async () => {
            ergebnis.unmount();
        });
        const bisher = (source.load as ReturnType<typeof vi.fn>).mock.calls.length;

        await act(async () => {
            await vi.advanceTimersByTimeAsync(10000);
        });

        expect((source.load as ReturnType<typeof vi.fn>).mock.calls.length).toBe(bisher);
    });
});

describe('httpMailboxChatSource', () => {
    const url = (id: number) => `/mailbox/${id}/ai-chat`;

    it('reads the conversation from the product route', async () => {
        const fetch = vi.fn(async () => new Response(JSON.stringify({ messages: [nachricht(1, 'assistant', 'Da.')], status: 'done', activity: [] }), { status: 200 }));
        const source = httpMailboxChatSource({ url, csrfToken: 't', fetch: fetch as never });

        expect(await source.load(7)).toEqual({ messages: [nachricht(1, 'assistant', 'Da.')], status: 'done', activity: [] });
        expect(fetch.mock.calls[0][0]).toBe('/mailbox/7/ai-chat');
    });

    it('answers null on a bad status instead of an empty conversation', async () => {
        // The difference matters: null means "keep what is there", an empty
        // state means "there is nothing" — and 503 says neither.
        const fetch = vi.fn(async () => new Response('nope', { status: 503 }));
        const source = httpMailboxChatSource({ url, csrfToken: 't', fetch: fetch as never });

        expect(await source.load(7)).toBeNull();
    });

    it('answers null when the body is not JSON at all', async () => {
        // A web server's own error page comes back with 200 often enough.
        const fetch = vi.fn(async () => new Response('<html>502</html>', { status: 200 }));
        const source = httpMailboxChatSource({ url, csrfToken: 't', fetch: fetch as never });

        expect(await source.load(7)).toBeNull();
    });

    it('sends with the CSRF token', async () => {
        const fetch = vi.fn(async () => new Response('{}', { status: 200 }));
        const source = httpMailboxChatSource({ url, csrfToken: 'geheim', fetch: fetch as never });

        expect(await source.send(7, 'Hallo')).toBeNull();

        const [, init] = fetch.mock.calls[0] as [string, RequestInit];
        expect((init.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('geheim');
        expect(init.body).toBe(JSON.stringify({ content: 'Hallo' }));
    });

    it('passes the server reason on instead of swallowing it', async () => {
        const fetch = vi.fn(async () => new Response(JSON.stringify({ error: 'Kein Token' }), { status: 422 }));
        const source = httpMailboxChatSource({ url, csrfToken: 't', fetch: fetch as never });

        expect(await source.send(7, 'Hallo')).toContain('Kein Token');
    });

    it('still reports a failure when the error body is unreadable', async () => {
        const fetch = vi.fn(async () => new Response('<html>', { status: 500 }));
        const source = httpMailboxChatSource({ url, csrfToken: 't', fetch: fetch as never });

        expect(await source.send(7, 'Hallo')).toBe('Senden fehlgeschlagen.');
    });
});

describe('MailboxChat — Bug #847', () => {
    const viele = (anzahl: number) =>
        Array.from({ length: anzahl }, (_, i) => nachricht(i + 1, i % 2 ? 'user' : 'assistant', `Nachricht ${i + 1}`));

    it('zeichnet nur das juengste Stueck des Verlaufs', async () => {
        // Der Takt holt alle zwei Sekunden neu. Bei einem gewachsenen Gespraech
        // zeichnet React sonst hunderte Blasen neu — genau waehrend jemand tippt.
        const { container, unmount } = await zeige(quelle({ load: vi.fn(async () => zustand({ messages: viele(100) })) }));

        const text = textOf(container);
        expect(text).toContain('Nachricht 100');
        expect(text).toContain('Nachricht 71');
        expect(text).not.toContain('Nachricht 70');
        unmount();
    });

    it('sagt, dass aeltere Nachrichten fehlen', async () => {
        // Ein stillschweigend gekuerzter Verlauf sieht aus wie ein Agent, der
        // etwas vergessen hat.
        const { container, unmount } = await zeige(quelle({ load: vi.fn(async () => zustand({ messages: viele(100) })) }));

        expect(textOf(container)).toContain('70 ältere Nachrichten nicht angezeigt');
        unmount();
    });

    it('sagt nichts, wenn nichts fehlt', async () => {
        const { container, unmount } = await zeige(quelle({ load: vi.fn(async () => zustand({ messages: viele(5) })) }));

        expect(textOf(container)).not.toContain('nicht angezeigt');
        unmount();
    });

    it('zaehlt eine einzelne verborgene Nachricht im Singular', async () => {
        const { container, unmount } = await zeige(quelle({ load: vi.fn(async () => zustand({ messages: viele(31) })) }));

        expect(textOf(container)).toContain('1 ältere Nachricht nicht angezeigt');
        unmount();
    });

    it('laesst das Produkt die Grenze verschieben', async () => {
        const { container, unmount } = await zeige(quelle({ load: vi.fn(async () => zustand({ messages: viele(10) })) }), {
            maxMessages: 3,
        });

        expect(textOf(container)).toContain('Nachricht 10');
        expect(textOf(container)).not.toContain('Nachricht 7');
        unmount();
    });
});

describe('MailboxChat — Mitlaufen beim Scrollen (Bug #847)', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    /** jsdom rechnet keine Layouts — die Masse werden hier gesetzt. */
    function masse(el: HTMLElement, { scrollHeight, clientHeight, scrollTop }: Record<string, number>) {
        Object.defineProperty(el, 'scrollHeight', { value: scrollHeight, configurable: true });
        Object.defineProperty(el, 'clientHeight', { value: clientHeight, configurable: true });
        el.scrollTop = scrollTop;
    }

    const behaelter = (container: HTMLElement) => container.querySelector('.overflow-y-auto') as HTMLElement;

    /** Rendert, laesst den ersten Abruf durch und gibt den Verlaufsbehaelter zurueck. */
    async function starten(source: MailboxChatSource) {
        let ergebnis!: ReturnType<typeof render>;
        await act(async () => {
            ergebnis = render(<MailboxChat account={konto} source={source} />);
        });

        return { ...ergebnis, el: behaelter(ergebnis.container) };
    }

    it('laeuft mit, solange man am Ende steht', async () => {
        let zweite = false;
        const source = quelle({
            load: vi.fn(async () =>
                zustand({
                    messages: zweite
                        ? [nachricht(1, 'assistant', 'Eins'), nachricht(2, 'assistant', 'Zwei')]
                        : [nachricht(1, 'assistant', 'Eins')],
                }),
            ),
        });

        const { el, unmount } = await starten(source);

        masse(el, { scrollHeight: 1000, clientHeight: 300, scrollTop: 700 }); // ganz unten
        await act(async () => {
            el.dispatchEvent(new Event('scroll'));
        });

        zweite = true;
        masse(el, { scrollHeight: 2000, clientHeight: 300, scrollTop: 700 });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(8100);
        });

        expect(el.scrollTop).toBe(2000);
        unmount();
    });

    it('reisst niemanden ans Ende, der gerade nachliest', async () => {
        // Der Fehler, den der Benutzer gemeldet hat: Wer hochscrollt, wird beim
        // naechsten Takt wieder heruntergerissen — Zuruecklesen unmoeglich.
        let zweite = false;
        const source = quelle({
            load: vi.fn(async () =>
                zustand({
                    messages: zweite
                        ? [nachricht(1, 'assistant', 'Eins'), nachricht(2, 'assistant', 'Zwei')]
                        : [nachricht(1, 'assistant', 'Eins')],
                }),
            ),
        });

        const { el, unmount } = await starten(source);

        masse(el, { scrollHeight: 1000, clientHeight: 300, scrollTop: 0 }); // ganz oben
        await act(async () => {
            el.dispatchEvent(new Event('scroll'));
        });

        zweite = true;
        masse(el, { scrollHeight: 2000, clientHeight: 300, scrollTop: 0 });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(8100);
        });

        expect(el.scrollTop).toBe(0);
        unmount();
    });

    it('faengt bei einem neuen Postfach wieder unten an', async () => {
        // Sonst bliebe „nicht am Ende" vom vorigen Gespraech haengen und der
        // neue Verlauf liefe nie mit.
        const source = quelle({ load: vi.fn(async () => zustand({ messages: [nachricht(1, 'assistant', 'Eins')] })) });
        const { el, container, rerender, unmount } = await starten(source);

        masse(el, { scrollHeight: 1000, clientHeight: 300, scrollTop: 0 });
        await act(async () => {
            el.dispatchEvent(new Event('scroll'));
        });

        await act(async () => {
            rerender(<MailboxChat account={{ id: 9, email: 'privat@example.test' }} source={source} />);
        });

        const neu = behaelter(container);
        masse(neu, { scrollHeight: 500, clientHeight: 300, scrollTop: 0 });
        await act(async () => {
            await vi.advanceTimersByTimeAsync(8100);
        });

        expect(neu.scrollTop).toBe(500);
        unmount();
    });
});

describe('MailboxChat — wie viele wirklich fehlen (Bug #847)', () => {
    const viele = (anzahl: number) =>
        Array.from({ length: anzahl }, (_, i) => nachricht(i + 1, 'user', `Nachricht ${i + 1}`));

    it('nennt die echte Zahl, nicht die gelieferte', async () => {
        // Der Server liefert nur die juengsten 50, das Gespraech hat 500. Wer
        // gegen das Gelieferte rechnet, meldet „20 aeltere" — und das ist
        // schlechter als gar keine Angabe.
        const { container, unmount } = await zeige(
            quelle({ load: vi.fn(async () => zustand({ messages: viele(50), total: 500 })) }),
        );

        expect(textOf(container)).toContain('470 ältere Nachrichten nicht angezeigt');
        unmount();
    });

    it('zaehlt das Gelieferte, wenn das Brain keine Zahl schickt', async () => {
        // Ein aelteres Brain kennt `total_messages` nicht. Dann ist die Angabe
        // eben ungenau — aber nichts geht kaputt.
        const { container, unmount } = await zeige(quelle({ load: vi.fn(async () => zustand({ messages: viele(40) })) }));

        expect(textOf(container)).toContain('10 ältere Nachrichten nicht angezeigt');
        unmount();
    });
});

describe('httpMailboxChatSource — Gesamtzahl', () => {
    it('liest die Gesamtzahl des Brains mit', async () => {
        const fetch = vi.fn(
            async () => new Response(JSON.stringify({ messages: [], status: 'done', activity: [], total_messages: 412 }), { status: 200 }),
        );
        const source = httpMailboxChatSource({ url: (id) => `/mailbox/${id}/ai-chat`, csrfToken: 't', fetch: fetch as never });

        expect((await source.load(7))?.total).toBe(412);
    });

    it('kommt ohne die Gesamtzahl aus', async () => {
        const fetch = vi.fn(async () => new Response(JSON.stringify({ messages: [], status: 'done', activity: [] }), { status: 200 }));
        const source = httpMailboxChatSource({ url: (id) => `/mailbox/${id}/ai-chat`, csrfToken: 't', fetch: fetch as never });

        expect((await source.load(7))?.total).toBeUndefined();
    });
});
