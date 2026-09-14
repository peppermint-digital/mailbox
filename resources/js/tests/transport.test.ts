import { describe, expect, it, vi } from 'vitest';
import { didSomething, postCounted, postJson } from '../src/transport';

function antwort(body: unknown, init: { ok?: boolean; status?: number; json?: boolean } = {}): Response {
    const { ok = true, status = 200, json = true } = init;

    return {
        ok,
        status,
        json: json ? async () => body : async () => { throw new SyntaxError('Unexpected token <'); },
    } as unknown as Response;
}

const opts = (fetchImpl: typeof globalThis.fetch) => ({ csrfToken: 'tok', fetch: fetchImpl });

describe('postJson', () => {
    it('sends the token and asks for JSON', async () => {
        const f = vi.fn(async () => antwort({}));
        await postJson('/mail/flag', { uid: 7 }, opts(f as never));

        const [url, init] = f.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/mail/flag');
        expect((init.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('tok');
        expect((init.headers as Record<string, string>).Accept).toBe('application/json');
        expect(init.credentials).toBe('same-origin');
        expect(init.body).toBe('{"uid":7}');
    });

    it('says true when the server said yes', async () => {
        const f = vi.fn(async () => antwort({}));
        await expect(postJson('/x', {}, opts(f as never))).resolves.toBe(true);
    });

    it('says false instead of throwing when the server said no', async () => {
        const f = vi.fn(async () => antwort({}, { ok: false, status: 500 }));
        await expect(postJson('/x', {}, opts(f as never))).resolves.toBe(false);
    });

    it('says false instead of throwing when the network is gone', async () => {
        const f = vi.fn(async () => { throw new TypeError('Failed to fetch'); });
        await expect(postJson('/x', {}, opts(f as never))).resolves.toBe(false);
    });
});

describe('postCounted', () => {
    it('reports what the endpoint says it did', async () => {
        const f = vi.fn(async () => antwort({ success: true, processed: 5, failed: 1 }));

        await expect(postCounted('/x', {}, opts(f as never))).resolves.toEqual({ processed: 5, failed: 1 });
    });

    it('distinguishes "ran and caught nothing" from "did not run"', async () => {
        // This is the whole point: HTTP 200 with processed 0 is an answer,
        // and it is not the same as no answer.
        const lief = vi.fn(async () => antwort({ success: true, processed: 0, failed: 0 }));
        const liefNicht = vi.fn(async () => antwort({}, { ok: false }));

        await expect(postCounted('/x', {}, opts(lief as never))).resolves.toEqual({ processed: 0, failed: 0 });
        await expect(postCounted('/x', {}, opts(liefNicht as never))).resolves.toBeNull();
    });

    it('trusts nothing when success is false, whatever the status was', async () => {
        const f = vi.fn(async () => antwort({ success: false, processed: 9 }));

        await expect(postCounted('/x', {}, opts(f as never))).resolves.toBeNull();
    });

    it('survives a body that is not JSON — a web server 413 page, say', async () => {
        const f = vi.fn(async () => antwort(null, { json: false }));

        await expect(postCounted('/x', {}, opts(f as never))).resolves.toBeNull();
    });

    it('fills in missing counts rather than reporting undefined', async () => {
        const f = vi.fn(async () => antwort({ success: true }));

        await expect(postCounted('/x', {}, opts(f as never))).resolves.toEqual({ processed: 0, failed: 0 });
    });
});

describe('didSomething', () => {
    it('is false when nothing ran', () => {
        expect(didSomething(null)).toBe(false);
    });

    it('is false when it ran and caught nothing', () => {
        expect(didSomething({ processed: 0, failed: 3 })).toBe(false);
    });

    it('is true as soon as one message was caught', () => {
        expect(didSomething({ processed: 1, failed: 4 })).toBe(true);
    });
});
