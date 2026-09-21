import { describe, expect, it, vi } from 'vitest';
import { act } from 'react';
import { useMailboxSearch, type MailboxSearch, type MailboxSearchSource, type SearchOutcome } from '../src/useMailboxSearch';
import type { RowMessage } from '../src/rows';
import { render } from './support/render';

function treffer(uid: number): RowMessage {
    return {
        uid,
        subject: `Treffer ${uid}`,
        from_address: 'kunde@example.test',
        from_name: 'Kunde',
        date: '2026-09-21T10:00:00+00:00',
        has_attachments: false,
        attachment_count: 0,
        is_read: true,
        is_flagged: false,
        preview: '',
    };
}

function haken(antwort: Partial<SearchOutcome<RowMessage>> = {}, folder = 'INBOX') {
    const gesucht: Parameters<MailboxSearchSource<RowMessage>['search']>[0][] = [];
    const ergebnisse: Array<[RowMessage[], number]> = [];
    const geleert = vi.fn();
    const fehler: Array<string | null> = [];

    const source: MailboxSearchSource<RowMessage> = {
        async search(params) {
            gesucht.push(params);

            return { messages: [treffer(1)], total: 1, ...antwort };
        },
    };

    const ref: { current: MailboxSearch } = { current: null as never };

    function Probe() {
        ref.current = useMailboxSearch<RowMessage>({
            source,
            folder,
            onResults: (m, t) => ergebnisse.push([m, t]),
            onCleared: geleert,
            onFailure: (f) => fehler.push(f),
        });

        return null;
    }

    render(<Probe />);

    return { hook: { result: ref }, gesucht, ergebnisse, geleert, fehler };
}

describe('was ueberhaupt eine Suche ist', () => {
    it('laesst ein einzelnes Zeichen nicht durch', () => {
        // Darunter schraenkt der Volltext nichts ein — der Server durchsucht
        // faktisch alles und gibt alles zurueck. Das ist keine Suche, das ist
        // Last.
        const { hook } = haken();

        act(() => hook.result.current.set('query', 'a'));

        expect(hook.result.current.canSearch).toBe(false);
    });

    it('laesst zwei Zeichen durch', () => {
        const { hook } = haken();

        act(() => hook.result.current.set('query', 'ab'));

        expect(hook.result.current.canSearch).toBe(true);
    });

    it('laesst auch einen Filter allein durch', () => {
        // „Alles von dieser Adresse" ist eine vollstaendige Frage, auch ohne
        // ein Wort im Volltext.
        const { hook } = haken();

        act(() => hook.result.current.set('from', 'kunde@example.test'));

        expect(hook.result.current.canSearch).toBe(true);
    });

    it('sucht gar nicht erst, wenn nichts einschraenkt', async () => {
        const { hook, gesucht } = haken();

        await act(async () => {
            await hook.result.current.search();
        });

        expect(gesucht).toHaveLength(0);
    });

    it('schickt einen zu kurzen Volltext NICHT mit, wenn ein Filter traegt', async () => {
        // Sonst haengt am Server ein Wort, das nichts einschraenkt — und die
        // Antwort waere eine andere als die, die der Haken zugesagt hat.
        const { hook, gesucht } = haken();

        act(() => {
            hook.result.current.set('from', 'kunde@example.test');
            hook.result.current.set('query', 'a');
        });
        await act(async () => {
            await hook.result.current.search();
        });

        expect(gesucht[0].query).toBe('');
        expect(gesucht[0].from).toBe('kunde@example.test');
    });
});

describe('ueber alle Ordner', () => {
    it('ist die Vorgabe', () => {
        // „Wo war nochmal die Mail von X" laesst sich im geoeffneten Ordner
        // nicht beantworten: Man sucht ja gerade den Ordner.
        const { hook } = haken();

        expect(hook.result.current.criteria.allFolders).toBe(true);
    });

    it('laesst sich abschalten und reicht den Ordner mit', async () => {
        const { hook, gesucht } = haken({}, 'Archiv');

        act(() => {
            hook.result.current.set('query', 'Angebot');
            hook.result.current.set('allFolders', false);
        });
        await act(async () => {
            await hook.result.current.search();
        });

        expect(gesucht[0].allFolders).toBe(false);
        expect(gesucht[0].folder).toBe('Archiv');
    });
});

describe('zuruecksetzen und verlassen sind zweierlei', () => {
    it('zuruecksetzen leert die Eingaben und holt die Liste zurueck', async () => {
        const { hook, geleert } = haken();

        act(() => hook.result.current.set('query', 'Angebot'));
        await act(async () => {
            await hook.result.current.search();
        });
        await act(async () => {
            await hook.result.current.clear();
        });

        expect(hook.result.current.criteria.query).toBe('');
        expect(hook.result.current.isSearchMode).toBe(false);
        expect(geleert).toHaveBeenCalledOnce();
    });

    it('verlassen laesst stehen, was jemand getippt hat', async () => {
        // Beim Ordner- oder Postfachwechsel. Ohne diesen Unterschied ist die
        // Eingabe weg, sobald man nebenan nachsieht.
        const { hook, geleert } = haken();

        act(() => hook.result.current.set('query', 'Angebot'));
        await act(async () => {
            await hook.result.current.search();
        });
        act(() => hook.result.current.leave());

        expect(hook.result.current.criteria.query).toBe('Angebot');
        expect(hook.result.current.isSearchMode).toBe(false);
        expect(geleert).not.toHaveBeenCalled();
    });
});

describe('das Ergebnis', () => {
    it('reicht die Treffer hinaus und merkt sich, was dazu zu sagen ist', async () => {
        const { hook, ergebnisse } = haken({
            messages: [treffer(1), treffer(2)],
            total: 2,
            foldersSearched: 7,
            totalFound: 40,
            searchedIndex: true,
        });

        act(() => hook.result.current.set('query', 'Angebot'));
        await act(async () => {
            await hook.result.current.search();
        });

        expect(ergebnisse[0][1]).toBe(2);
        expect(hook.result.current.foldersSearched).toBe(7);
        // Gedeckelte Antwort: 2 gezeigt, 40 gefunden — der Unterschied ist die
        // Information.
        expect(hook.result.current.totalFound).toBe(40);
        expect(hook.result.current.searchedIndex).toBe(true);
    });

    it('meldet eine Ablehnung des Servers und reicht KEINE Treffer hinaus', async () => {
        const { hook, ergebnisse, fehler } = haken({ failure: 'Suche nicht möglich.' });

        act(() => hook.result.current.set('query', 'Angebot'));
        await act(async () => {
            await hook.result.current.search();
        });

        expect(fehler).toContain('Suche nicht möglich.');
        expect(ergebnisse).toHaveLength(0);
    });

    it('hoert auf zu laden, auch wenn die Suche wirft', async () => {
        // Sonst dreht sich der Ladebalken weiter und die Maske sieht aus, als
        // arbeite sie noch.
        const source: MailboxSearchSource<RowMessage> = {
            search: () => Promise.reject(new Error('Netz weg')),
        };
        const ref: { current: MailboxSearch } = { current: null as never };

        function Probe() {
            ref.current = useMailboxSearch<RowMessage>({
                source,
                folder: 'INBOX',
                onResults: () => {},
                onCleared: () => {},
            });

            return null;
        }

        render(<Probe />);
        const hook = { result: ref };

        act(() => hook.result.current.set('query', 'Angebot'));
        await act(async () => {
            await hook.result.current.search().catch(() => {});
        });

        expect(hook.result.current.searching).toBe(false);
    });

    it('fragt auf Wunsch das Postfach statt des Verzeichnisses', async () => {
        const { hook, gesucht } = haken();

        act(() => hook.result.current.set('query', 'Angebot'));
        await act(async () => {
            await hook.result.current.search({ fresh: true });
        });

        expect(gesucht[0].fresh).toBe(true);
    });
});
