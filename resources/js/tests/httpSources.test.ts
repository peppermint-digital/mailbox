import { describe, expect, it, vi } from 'vitest';
import { httpMailboxSources, type MailboxRoutes } from '../src/httpSources';

const routen: MailboxRoutes = {
    folders: (id, { refresh }) => `/emails/${id}/folders${refresh ? '?refresh=1' : ''}`,
    targets: (id) => `/emails/${id}/all-folders`,
    messages: (id, { folder, page, grouped }) => `/emails/${id}/${grouped ? 'threads' : 'messages'}?folder=${folder}&page=${page}`,
    message: (id, { uid, folder }) => `/emails/${id}/message/${uid}?folder=${folder}`,
};

function quellen(antwort: unknown, status = 200) {
    const fetch = vi.fn(async () => new Response(JSON.stringify(antwort), { status }));

    return { fetch, q: httpMailboxSources({ routes: routen, accountId: () => 7, fetch: fetch as never }) };
}

const zeile = { uid: 3, subject: 'Hallo' };

describe('httpMailboxSources — Ordner', () => {
    it('asks the address the product named', async () => {
        const { fetch, q } = quellen({ success: true, folders: [{ name: 'INBOX', path: 'INBOX' }] });

        await q.folders.list({ accountId: 7, refresh: true });

        expect(fetch.mock.calls[0][0]).toBe('/emails/7/folders?refresh=1');
    });

    it('passes a refusal on with its reason', async () => {
        // „Der Server hat abgelehnt" und „niemand hat geantwortet" brauchen
        // verschiedene Worte auf dem Bildschirm.
        const { q } = quellen({ success: false, message: 'Postfach gesperrt' });

        expect(await q.folders.list({ accountId: 7, refresh: false })).toEqual({ failure: 'Postfach gesperrt' });
    });

    it('lets a network failure stay a network failure', async () => {
        // NICHT abfangen: Der Haken unterscheidet die beiden Faelle genau daran.
        const fetch = vi.fn(async () => {
            throw new TypeError('Failed to fetch');
        });
        const q = httpMailboxSources({ routes: routen, accountId: () => 7, fetch: fetch as never });

        await expect(q.folders.list({ accountId: 7, refresh: false })).rejects.toThrow();
    });

    it('treats a missing folder list as empty, not as broken', async () => {
        // Ohne den Ersatz rendert die Leiste `undefined.map` und nimmt die Seite mit.
        const { q } = quellen({ success: true });

        expect(await q.folders.list({ accountId: 7, refresh: false })).toEqual([]);
    });

    it('answers an empty target list rather than failing the screen', async () => {
        // Das fuellt ein „Verschieben nach…"-Menue. Ein kurzes Menue ist besser
        // als ein Postfach, das gar nicht mehr da ist.
        const fetch = vi.fn(async () => {
            throw new TypeError('Failed to fetch');
        });
        const q = httpMailboxSources({ routes: routen, accountId: () => 7, fetch: fetch as never });

        expect(await q.folders.listTargets!({ accountId: 7 })).toEqual([]);
    });

    it('has no listTargets when the product has no such route', async () => {
        // Sonst ruft der Haken eine Adresse, die es nicht gibt, und das „Verschieben
        // nach…"-Menue bleibt aus Gruenden leer, die niemand sieht.
        const { routes: _weg, ...rest } = { routes: routen };
        const ohne = httpMailboxSources({
            routes: { folders: routen.folders, messages: routen.messages, message: routen.message },
            accountId: () => 7,
            fetch: (async () => new Response('{}')) as never,
        });

        expect(ohne.folders.listTargets).toBeUndefined();
        expect(rest).toBeDefined();
    });
});

describe('httpMailboxSources — Liste', () => {
    it('builds the address from the product rules, grouped or not', async () => {
        const { fetch, q } = quellen({ success: true, messages: [], threads: [], total: 0 });

        await q.messages.list({ accountId: 7, folder: 'INBOX', page: 2, grouped: true, refresh: false });

        expect(fetch.mock.calls[0][0]).toBe('/emails/7/threads?folder=INBOX&page=2');
    });

    it('fills in what the answer left out', async () => {
        // Ein fehlender Schluessel heisst leere Seite, nicht kaputte Seite.
        const { q } = quellen({ success: true });

        expect(await q.messages.list({ accountId: 7, folder: 'INBOX', page: 1, grouped: false, refresh: false })).toEqual({
            messages: [],
            threads: [],
            total: 0,
        });
    });

    it('passes a refusal on', async () => {
        const { q } = quellen({ success: false, message: 'Ordner gibt es nicht' });

        expect(await q.messages.list({ accountId: 7, folder: 'Weg', page: 1, grouped: false, refresh: false })).toEqual({
            failure: 'Ordner gibt es nicht',
        });
    });
});

describe('httpMailboxSources — Öffnen', () => {
    it('asks for the uid in the folder the caller believes in', async () => {
        const { fetch, q } = quellen({ success: true, message: { uid: 3 }, folder: 'INBOX' });

        await q.message.open({ message: zeile as never, folder: 'INBOX' });

        expect(fetch.mock.calls[0][0]).toBe('/emails/7/message/3?folder=INBOX');
    });

    it('reports the folder the answer names, not the one that was asked for', async () => {
        // Eine verschobene Nachricht ist weiterhin auffindbar — der Aufrufer muss
        // nur erfahren, wohin sie gewandert ist.
        const { q } = quellen({ success: true, message: { uid: 3 }, folder: 'Archiv' });

        expect(await q.message.open({ message: zeile as never, folder: 'INBOX' })).toEqual({
            message: { uid: 3 },
            folder: 'Archiv',
        });
    });

    it('says null when the answer names no folder at all', async () => {
        const { q } = quellen({ success: true, message: { uid: 3 } });

        expect((await q.message.open({ message: zeile as never, folder: 'INBOX' })) as { folder: unknown }).toMatchObject({
            folder: null,
        });
    });

    it('uses the account it is asked for at call time', async () => {
        // Die Kennung wechselt, waehrend jemand Postfaecher durchklickt. Eine
        // beim Bauen festgehaltene Kennung oeffnet die Mail im falschen Postfach.
        let konto: number = 7;
        const fetch = vi.fn(async () => new Response(JSON.stringify({ success: true, message: {} })));
        const q = httpMailboxSources({ routes: routen, accountId: () => konto, fetch: fetch as never });

        konto = 9;
        await q.message.open({ message: zeile as never, folder: 'INBOX' });

        expect(fetch.mock.calls[0][0]).toBe('/emails/9/message/3?folder=INBOX');
    });
});

describe('die Such-Quelle', () => {
    const anfrage = {
        query: 'Angebot',
        from: '',
        subject: '',
        since: '',
        unseen: false,
        allFolders: true,
        folder: 'INBOX',
        fresh: false,
    };

    /** Wie `quellen()`, aber mit einer Such-Route. */
    function mitSuche(antwort: unknown, route?: MailboxRoutes['search']) {
        const fetch = vi.fn(async () => new Response(JSON.stringify(antwort), { status: 200 }));
        const q = httpMailboxSources({
            routes: { ...routen, search: route ?? ((id, p) => `/emails/${id}/search?q=${p.query}&scope=${p.allFolders ? 'all' : 'one'}`) },
            accountId: () => 7,
            fetch: fetch as never,
        });

        return { fetch, q };
    }

    it('gibt es nur, wenn das Produkt eine Such-Route hat', () => {
        // Ein Produkt ohne Suche soll auch kein Suchfeld zeigen — und das
        // entscheidet sich hier, nicht in der Maske.
        expect(quellen({ success: true }).q.search).toBeUndefined();
    });

    it('reicht die Eingaben an die Route des Produkts', async () => {
        const { fetch, q } = mitSuche({ success: true, messages: [], total: 0 });

        await q.search!.search(anfrage);

        expect(fetch.mock.calls[0][0]).toBe('/emails/7/search?q=Angebot&scope=all');
    });

    it('nimmt die Zahl der durchsuchten Ordner mit', async () => {
        // Ohne sie sieht eine Suche, die still das halbe Postfach ausgelassen
        // hat, wie eine vollstaendige aus.
        const { q } = mitSuche({ success: true, messages: [zeile], total: 1, folders_searched: 7, total_found: 40 });

        const ergebnis = await q.search!.search(anfrage);

        expect(ergebnis.foldersSearched).toBe(7);
        expect(ergebnis.totalFound).toBe(40);
    });

    it('macht aus einer Ablehnung kein leeres Ergebnis', async () => {
        // Sonst sieht „Der Server hat abgelehnt" aus wie „nichts gefunden" —
        // und niemand erfaehrt den Grund.
        const { q } = mitSuche({ success: false, message: 'Diese Suche schränkt nichts ein.' });

        const ergebnis = await q.search!.search(anfrage);

        expect(ergebnis.failure).toBe('Diese Suche schränkt nichts ein.');
    });
});
