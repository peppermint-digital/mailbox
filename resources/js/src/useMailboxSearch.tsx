import { useCallback, useState } from 'react';
import type { RowMessage } from './rows';

/**
 * Die Postfach-Suche: Eingaben, Filter und was über das Ergebnis zu sagen ist.
 *
 * ## Warum das ins Paket gehört
 *
 * Die Suche lag bis zum 21.09.2026 in einer 2443 Zeilen langen Produktseite —
 * und fehlte damit allen anderen Produkten, obwohl der PHP-Vertrag `search()`
 * und `searchAll()` längst kennt. Dieselbe Lücke wie beim ImapService, eine
 * Etage höher: Der dickste Abnehmer hielt etwas, das allen zusteht.
 *
 * Drei Entscheidungen darin sind teuer erkauft und sollen niemand zweimal
 * treffen müssen:
 *
 * **Mindestens zwei Zeichen, oder ein gesetzter Filter.** Darunter schränkt
 * der Volltext nichts ein — der Server durchsucht faktisch alles und gibt
 * alles zurück. Das ist keine Suche, das ist Last.
 *
 * **Über alle Ordner ist die Vorgabe.** „Wo war nochmal die Mail von X" lässt
 * sich im geöffneten Ordner nicht beantworten: Man sucht ja gerade den Ordner.
 *
 * **Zurücksetzen und Verlassen sind zweierlei.** {@link MailboxSearch.clear}
 * leert die Eingaben und holt die normale Liste zurück.
 * {@link MailboxSearch.leave} verlässt nur den Such-Modus und lässt stehen,
 * was jemand getippt hat — beim Ordner- oder Postfachwechsel. Ohne diesen
 * Unterschied ist die Eingabe weg, sobald man nebenan nachsieht.
 *
 * ## Was beim Produkt bleibt
 *
 * Die Trefferliste selbst: Sie gehört der Liste, weil die auch ohne Suche
 * gefüllt wird — beim Blättern, beim Ordnerwechsel, nach einer Sammelaktion.
 * Der Haken reicht die Treffer über `onResults` hinaus, und dort nimmt sie
 * {@link useMailboxList.showRows} entgegen.
 *
 * Und die Wörter. Hier steht keines.
 */

/** Was jemand eingegeben hat. */
export interface SearchCriteria {
    /** Volltext. Erst ab zwei Zeichen eine Einschränkung. */
    query: string;
    from: string;
    subject: string;
    /** ISO-Datum, ab wann. Leer heisst: egal. */
    since: string;
    /** Nur Ungelesene. */
    unseen: boolean;
    /** Über alle Ordner statt nur im geöffneten. */
    allFolders: boolean;
}

/** Was der Server zurückgibt. Alles ausser `messages` und `total` ist freiwillig. */
export interface SearchOutcome<M> {
    messages: M[];
    total: number;
    /** Wie viele Ordner durchsucht wurden — für „12 Treffer in 7 Ordnern". */
    foldersSearched?: number;
    /** Wie viele es insgesamt gäbe, wenn die Antwort gedeckelt ist. */
    totalFound?: number;
    /** Kam die Antwort aus einem Verzeichnis statt vom Postfach? */
    searchedIndex?: boolean;
    /** Gesetzt heisst: Der Server hat geantwortet und abgelehnt. */
    failure?: string;
}

export interface MailboxSearchSource<M extends RowMessage> {
    /**
     * Sucht. Wirft bei einem Netzfehler; gibt `failure` zurück, wenn der
     * Server geantwortet und abgelehnt hat.
     */
    search(params: SearchCriteria & { folder: string; fresh: boolean }): Promise<SearchOutcome<M>>;
}

export interface UseMailboxSearchOptions<M extends RowMessage> {
    source: MailboxSearchSource<M>;
    /** Der Ordner, in dem gesucht wird, wenn nicht über alle gesucht wird. */
    folder: string;
    /** Nimmt die Treffer entgegen — meist `list.showRows`. */
    onResults: (messages: M[], total: number) => void;
    /** Nach dem Zurücksetzen: die normale Liste wiederherstellen. */
    onCleared: () => Promise<void> | void;
    /** Ein Fehler, den ein Mensch sehen soll. Null löscht ihn. */
    onFailure?: (failure: string | null) => void;
}

export interface MailboxSearch {
    criteria: SearchCriteria;
    /** Ändert ein Feld, ohne die übrigen anzufassen. */
    set: <K extends keyof SearchCriteria>(feld: K, wert: SearchCriteria[K]) => void;
    /** Schränkt die Eingabe überhaupt etwas ein? */
    canSearch: boolean;
    searching: boolean;
    /** Zeigt die Liste gerade Treffer statt eines Ordners? */
    isSearchMode: boolean;
    foldersSearched: number;
    totalFound: number;
    searchedIndex: boolean;
    /** `fresh` umgeht ein Verzeichnis und fragt das Postfach direkt. */
    search: (options?: { fresh?: boolean }) => Promise<void>;
    clear: () => Promise<void>;
    leave: () => void;
}

const LEER: SearchCriteria = {
    query: '',
    from: '',
    subject: '',
    since: '',
    unseen: false,
    // Die Vorgabe mit Absicht — siehe oben.
    allFolders: true,
};

export function useMailboxSearch<M extends RowMessage>({
    source,
    folder,
    onResults,
    onCleared,
    onFailure,
}: UseMailboxSearchOptions<M>): MailboxSearch {
    const [criteria, setCriteria] = useState<SearchCriteria>(LEER);
    const [searching, setSearching] = useState(false);
    const [isSearchMode, setIsSearchMode] = useState(false);
    const [foldersSearched, setFoldersSearched] = useState(0);
    const [totalFound, setTotalFound] = useState(0);
    const [searchedIndex, setSearchedIndex] = useState(false);

    const set = useCallback(<K extends keyof SearchCriteria>(feld: K, wert: SearchCriteria[K]) => {
        setCriteria((vorher) => ({ ...vorher, [feld]: wert }));
    }, []);

    const canSearch =
        criteria.query.trim().length >= 2 ||
        criteria.from.trim() !== '' ||
        criteria.subject.trim() !== '' ||
        criteria.since !== '' ||
        criteria.unseen;

    const search = useCallback(
        async ({ fresh = false }: { fresh?: boolean } = {}) => {
            if (!canSearch) {
                return;
            }

            setSearching(true);
            setIsSearchMode(true);
            onFailure?.(null);

            try {
                const ergebnis = await source.search({
                    ...criteria,
                    // Unter zwei Zeichen geht der Volltext gar nicht erst
                    // hinaus: Er waere keine Einschraenkung, nur Last.
                    query: criteria.query.trim().length >= 2 ? criteria.query.trim() : '',
                    from: criteria.from.trim(),
                    subject: criteria.subject.trim(),
                    folder,
                    fresh,
                });

                if (ergebnis.failure !== undefined) {
                    onFailure?.(ergebnis.failure);

                    return;
                }

                onResults(ergebnis.messages, ergebnis.total);
                setFoldersSearched(ergebnis.foldersSearched ?? 1);
                setTotalFound(ergebnis.totalFound ?? ergebnis.total);
                setSearchedIndex(ergebnis.searchedIndex ?? false);
            } finally {
                // Auch nach einem Fehler: Sonst dreht sich der Ladebalken
                // weiter und die Maske sieht aus, als arbeite sie noch.
                setSearching(false);
            }
        },
        [canSearch, criteria, folder, onFailure, onResults, source],
    );

    const clear = useCallback(async () => {
        setCriteria(LEER);
        setIsSearchMode(false);

        await onCleared();
    }, [onCleared]);

    const leave = useCallback(() => {
        setIsSearchMode(false);
    }, []);

    return {
        criteria,
        set,
        canSearch,
        searching,
        isSearchMode,
        foldersSearched,
        totalFound,
        searchedIndex,
        search,
        clear,
        leave,
    };
}

export default useMailboxSearch;
