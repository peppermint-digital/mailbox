/**
 * Folder rules for the browser half.
 *
 * The PHP side of this package judges folders by name (`Folders\FolderNames`);
 * this is the part a list view needs, and it asks the SERVER's answer first.
 *
 * IMAP marks its special folders with flags (`\\Sent`, `\\Trash`, …). Where a
 * server sets them, they are the truth; the name is only the fallback.
 */

export interface FolderLike {
    path: string;
    flags?: string[] | null;
}

/** The special-use marks that make a folder a system folder. */
const SPECIAL = ['sent', 'drafts', 'trash', 'archive', 'junk', 'all', 'important', 'flagged'];

/**
 * May this folder be renamed or deleted?
 *
 * INBOX never may — it is the one folder every mailbox has, and losing it
 * loses the mailbox. The rest follows the server's own marks, which mirrors
 * the lock the server enforces anyway: offering a button that the backend will
 * refuse is worse than not offering it.
 */
export function isProtectedFolder(folder: FolderLike): boolean {
    if (folder.path.toUpperCase() === 'INBOX') {
        return true;
    }

    const flags = (folder.flags ?? []).map((f) => f.toLowerCase());

    return SPECIAL.some((mark) => flags.some((flag) => flag.includes(mark)));
}

/**
 * The standard folders, and the names they hide behind.
 *
 * Mirrors `Folders\FolderNames::STANDARD` on the PHP side of this package —
 * the same table, because the same question gets asked in both halves.
 *
 * **The encoded spellings are not decoration.** IMAP encodes non-ASCII folder
 * names in a modified UTF-7 (RFC 3501): Office 365 reports the German drafts
 * as `Entw&APw-rfe` and the trash as `Gel&APY-schte Elemente`. A comparison
 * against "entwürfe" finds nothing there.
 */
export const STANDARD_FOLDER_ALIASES = {
    Sent: ['sent', 'sent items', 'sent mail', 'gesendet', 'gesendete objekte', 'gesendete elemente', 'gesendete&apy-', 'ges&apy-ndet'],
    Drafts: ['drafts', 'entwürfe', 'entwurf', 'entwurfe', 'entw&apw-rfe'],
    Trash: ['trash', 'deleted items', 'gelöschte elemente', 'geloschte elemente', 'papierkorb', 'gel&apy-schte'],
    Archive: ['archive', 'archiv', 'archived', 'archiviert', 'all mail', 'alle nachrichten'],
} as const satisfies Record<string, readonly string[]>;

export type StandardFolder = keyof typeof STANDARD_FOLDER_ALIASES;

/** The IMAP special-use flag that marks each standard folder. */
const FLAG_OF: Record<StandardFolder, string> = {
    Sent: 'sent',
    Drafts: 'drafts',
    Trash: 'trash',
    Archive: 'archive',
};

function containsAny(haystack: string, needles: readonly string[]): boolean {
    const lower = haystack.toLowerCase();

    return needles.some((needle) => needle !== '' && lower.includes(needle));
}

/**
 * Does this folder look like the given standard folder?
 *
 * **Flags first.** A server that sets `\Drafts` has answered the question, and
 * its answer beats any guess at the name. Only where the mark is missing does
 * the name decide — and then by the alias table, not by a hand-written pattern
 * that will miss the encoded spellings.
 *
 * Matches on substrings, on purpose: real folders are called `INBOX.Drafts`,
 * `[Gmail]/Entwürfe`, `Gelöschte Elemente`.
 */
export function looksLikeFolder(folder: FolderLike & { name?: string | null }, kind: StandardFolder): boolean {
    const flags = (folder.flags ?? []).map((flag) => flag.toLowerCase());

    if (flags.some((flag) => flag.includes(FLAG_OF[kind]))) {
        return true;
    }

    const aliases = STANDARD_FOLDER_ALIASES[kind];

    return containsAny(folder.path ?? '', aliases) || containsAny(folder.name ?? '', aliases);
}

/** Which standard folder this looks like, or null. */
export function classifyFolder(folder: FolderLike & { name?: string | null }): StandardFolder | null {
    const kinds = Object.keys(STANDARD_FOLDER_ALIASES) as StandardFolder[];

    return kinds.find((kind) => looksLikeFolder(folder, kind)) ?? null;
}

/**
 * Die Anzeigenamen der Standardordner — und warum sie NICHT aus der Tabelle
 * oben kommen.
 *
 * Gemeldet am 30.10.2026 beim Rollout von v0.187.0 (#9134): Die Verwaltung
 * zeigt auf dem Telefon „INBOX" als Ordnernamen, weil der Name roh vom Server
 * kommt. Exchange und Office 365 melden englische oder kodierte Namen
 * (`INBOX`, `Sent Items`, `Entw&APw-rfe`), Dovecot deutsche — dasselbe
 * Postfach heisst in zwei Produkten anders, und niemand hat das entschieden.
 *
 * ## Zwei verschiedene Fragen, zwei Tabellen
 *
 * `STANDARD_FOLDER_ALIASES` beantwortet „darf man hier einen Entwurf
 * weiterbearbeiten" und „darf dieser Ordner geloescht werden". Das ist
 * VERHALTEN, und es passt bewusst grosszuegig (Teiltreffer auf dem Pfad), weil
 * ein verpasster Entwurfsordner teurer ist als einer zu viel.
 *
 * Diese Tabelle hier beantwortet „wie heisst der Ordner auf dem Schirm". Das
 * ist ANZEIGE, und dort ist Grosszuegigkeit ein Fehler: Ein Ordner „Archiv
 * 2024" darf nicht als „Archiv" erscheinen, und `INBOX.Kunden` ist nicht der
 * Posteingang. Darum vergleicht sie **genau** und nicht auf Teiltreffer.
 *
 * Die beiden zu verschmelzen waere die naheliegende Vereinfachung und die
 * falsche: Dann muesste eine Aenderung an der Anzeige am Verhalten
 * gegengeprueft werden, und umgekehrt.
 */
const ANZEIGENAMEN: readonly { name: string; flag: string | null; aliase: readonly string[] }[] = [
    /*
     * Die Reihenfolge entscheidet, und der Posteingang steht ZULETZT.
     * `INBOX.Drafts` endet auf „drafts" und faengt mit „inbox" an — wer zuerst
     * auf den Posteingang prueft, benennt jeden Unterordner so.
     */
    { name: 'Entwürfe', flag: 'drafts', aliase: ['drafts', 'entwürfe', 'entwurfe', 'entwurf', 'entw&apw-rfe'] },
    { name: 'Gesendet', flag: 'sent', aliase: ['sent', 'sent items', 'sent mail', 'gesendet', 'gesendete objekte', 'gesendete elemente'] },
    { name: 'Spam', flag: 'junk', aliase: ['junk', 'junk e-mail', 'junk-e-mail', 'spam', 'bulk mail'] },
    { name: 'Papierkorb', flag: 'trash', aliase: ['trash', 'deleted', 'deleted items', 'papierkorb', 'gelöschte elemente', 'geloschte elemente', 'gel&apy-schte elemente'] },
    { name: 'Archiv', flag: 'archive', aliase: ['archive', 'archiv', 'archived', 'archiviert'] },
    { name: 'Posteingang', flag: 'inbox', aliase: ['inbox', 'posteingang'] },
];

/** Der letzte Abschnitt eines IMAP-Pfades. Trenner ist je nach Server `.` oder `/`. */
function letzterAbschnitt(pfad: string): string {
    const teile = pfad.split(/[./]/);

    return teile[teile.length - 1] ?? pfad;
}

/**
 * Wie der Ordner auf dem Schirm heissen soll.
 *
 * Reihenfolge der Fragen, und sie ist dieselbe wie in `looksLikeFolder`:
 * **Die Flagge des Servers zuerst.** Setzt er `\Drafts`, hat er die Frage
 * beantwortet, und sein Name ist nur noch Dekoration. Erst ohne Flagge
 * entscheidet der Name — und dann genau, nicht auf Teiltreffer.
 *
 * Kein Treffer heisst: Der Name des Servers bleibt stehen. Ein eigener Ordner
 * heisst, wie der Mensch ihn genannt hat.
 */
export function ordnerAnzeigename(folder: FolderLike & { name?: string | null }): string {
    const roh = (folder.name ?? folder.path ?? '').trim();
    const flags = (folder.flags ?? []).map((f) => f.toLowerCase());

    for (const eintrag of ANZEIGENAMEN) {
        if (eintrag.flag && flags.some((flag) => flag.includes(eintrag.flag!))) {
            return eintrag.name;
        }
    }

    const kandidaten = [roh.toLowerCase(), letzterAbschnitt(folder.path ?? '').toLowerCase()];

    for (const eintrag of ANZEIGENAMEN) {
        if (kandidaten.some((k) => eintrag.aliase.includes(k))) {
            return eintrag.name;
        }
    }

    return roh;
}
