import type { MailboxHeaderLabels } from './MailboxHeader';
import type { MailConversationPaneLabels } from './MailConversationPane';
import type { MailFolderManagerLabels } from './MailFolderManager';
import type { MailListToolbarLabels } from './MailListToolbar';
import type { MailMessageListLabels } from './MailMessageList';
import type { MailMessageViewLabels } from './MailMessageView';
import type { MailWerkzeugleisteLabels } from './MailWerkzeugleiste';

/**
 * Die deutschen Woerter des Mailbrowsers — als VORGABE, nicht als Vorschrift.
 *
 * ## Warum sie jetzt hier stehen
 *
 * Gemessen am 19.10.2026: Dieselben Textbloecke lagen in allen vier Produkten,
 * **wortgleich**, zusammen rund 500 Zeilen. Nur sechs Schluessel wichen ab, und
 * fuenf davon sagt der Projekt-Manager genauer, weil er ein Archiv hat.
 *
 * Die Folge der Kopien war nicht Mehrarbeit, sondern Stillstand: Eine bessere
 * Formulierung an einer Stelle erreichte keine andere. Genau dieselbe Lage wie
 * beim Editor vor dem 14.10.2026 — drei Umsetzungen desselben Gedankens.
 *
 * ## Was damit NICHT gilt
 *
 * Die Regel „ein Paket, das Saetze ausliefert, liefert eine Sprache aus"
 * bleibt richtig — deshalb sind das **Vorgaben**. Wer andere Woerter will,
 * uebergibt sie; wer eine andere Sprache braucht, uebergibt ein eigenes
 * Objekt und beruehrt diese Datei nie. Das Paket IMPONIERT keine Sprache, es
 * bringt eine mit.
 *
 * ## Wie ein Produkt abweicht
 *
 *     const listenTexte = { ...germanLabels.list, empty: 'Keine Tickets' };
 *
 * Und genau so behaelt der Manager seine fuenf genaueren Saetze. Beim
 * Zusammenlegen darf nichts wegfallen: Wer mehr weiss, sagt mehr.
 *
 * ## Vollstaendigkeit erzwingt der Typ
 *
 * Jedes Objekt ist auf seine Schnittstelle typisiert. Kommt im Paket ein
 * Pflichtfeld dazu, bricht DIESE Datei beim Bauen — und nicht erst die
 * Oberflaeche eines Produkts, in der dann `undefined` steht.
 */

/** Die Liste der Nachrichten. */
const list: MailMessageListLabels = {
    unread: 'Ungelesen',
    outboundSender: 'Ihre Antwort',
    outboundBadge: 'gesendet',
    outboundBadgeTitle: 'Aus dem Postfach gesendet',
    expandThread: 'Konversation aufklappen',
    collapseThread: 'Konversation zuklappen',
    newest: 'Neueste',
    newestTitle: 'Die jüngste Nachricht dieser Konversation',
    unreadCount: (anzahl) => `${anzahl} neu`,
    unreadCountTitle: (anzahl, gesamt) => `${anzahl} von ${gesamt} ungelesen`,
    archived: 'Archiv',
    archivedTitle: 'Liegt nicht mehr im Postfach',
    foundInFolder: (ordner) => `Gefunden in ${ordner}`,
    flagged: 'Markiert',
    hasAttachments: 'Hat Anhänge',
    selectMessage: (betreff) => `„${betreff || 'Ohne Betreff'}" auswählen`,
    empty: 'Keine E-Mails in diesem Ordner',
    emptyInSearch: 'Keine E-Mails gefunden',
};

/** Der Gespraechsverlauf aus der Ablage. */
const conversation: MailConversationPaneLabels = {
    empty: 'In dieser Konversation liegt noch nichts.',
    loading: 'Verlauf wird geladen …',
    showOmitted: 'Zitat & Signatur zeigen',
    hideOmitted: 'Ausblenden',
    quote: 'Zitierter Text',
    signature: 'Signatur',
    footer: 'Fußzeile',
    openOriginal: 'Original öffnen',
    notInMailbox: 'Nicht mehr im Postfach',
    attachments: (n: number) => (n === 1 ? '1 Anhang' : `${n} Anhänge`),
    nothingWritten: 'Kein Text — vermutlich nur ein Anhang.',
    purged: (wann: string, wer: string) => `Aus der Ablage entfernt am ${wann} durch ${wer}.`,
    showConversation: 'Gesprächsverlauf',
    showFullMessage: 'Ganze Nachricht',
};

/** Die Leseansicht einer Nachricht. */
const view: MailMessageViewLabels = {
    empty: 'Wählen Sie eine E-Mail aus',
    archived: 'Archiv',
    archivedTitle: 'Liegt nicht mehr im Postfach',
    editDraft: 'Weiter bearbeiten',
    reply: 'Antworten',
    replyAll: 'Allen antworten',
    forward: 'Weiterleiten',
    markRead: 'Als gelesen markieren',
    markUnread: 'Als ungelesen markieren',
    flag: 'Markieren',
    unflag: 'Markierung entfernen',
    archive: 'Archivieren',
    archiveConversation: (anzahl) => `Ganze Konversation archivieren (${anzahl})`,
    delete: 'Löschen',
    from: 'Von:',
    date: 'Datum:',
    to: 'An:',
    cc: 'CC:',
    downloadAttachment: (datei) => `${datei} herunterladen`,
    body: {
        empty: 'Kein Inhalt',
        remoteBlocked: 'Externe Bilder wurden zum Schutz vor Tracking blockiert.',
        loadImages: 'Bilder laden',
        frameTitle: 'Nachrichteninhalt',
    },
};

/** Ordner anlegen, umbenennen, loeschen. */
const folders: MailFolderManagerLabels = {
    create: 'Anlegen',
    rename: 'Umbenennen',
    delete: 'Löschen',
    cancel: 'Abbrechen',
    createTitle: 'Neuer Ordner',
    renameTitle: 'Ordner umbenennen',
    createHint: 'Name des neuen Ordners. Für einen Unterordner „Übergeordnet/Name" eingeben.',
    namePlaceholder: 'Ordnername',
    deleteTitle: 'Ordner löschen?',
    deleteWarning: (name) => `„${name}" wird gelöscht. Alle enthaltenen E-Mails gehen dabei verloren. Das lässt sich nicht rückgängig machen.`,
    protectedFolder: 'Systemordner — nicht änderbar',
};

/** Der Kopf: Postfachwahl, Verfassen, Adressbuch. */
const header: MailboxHeaderLabels = {
    addressBook: 'Adressbuch',
    compose: 'Neue E-Mail',
    refresh: 'Aktualisieren',
    refreshing: 'Wird aktualisiert …',
    accountPicker: 'Postfach auswählen',
    noAccounts: 'Kein Postfach zugeteilt',
};

/** Suche, Filter und die Mehrfachauswahl. */
const toolbar: MailListToolbarLabels = {
    toggleFolders: (gezeigt) => (gezeigt ? 'Ordner ausblenden' : 'Ordner einblenden'),
    searchPlaceholder: 'E-Mails durchsuchen...',
    clearSearch: 'Suche zurücksetzen',
    filters: 'Filter',
    search: 'Suchen',
    searching: 'Suche läuft …',
    filterFrom: 'Von',
    filterFromPlaceholder: 'absender@…',
    filterSubject: 'Betreff',
    filterSubjectPlaceholder: 'Betreff enthält…',
    filterSince: 'Datum ab',
    filterUnseen: 'Nur ungelesene',
    filterAllFolders: 'In allen Ordnern',
    filterAllFoldersHint: '(ohne Papierkorb, Spam, Entwürfe)',
    reset: 'Zurücksetzen',
    searchResultsFor: (q) => `Suchergebnisse für "${q}"`,
    searchResultsFiltered: 'Suchergebnisse (Filter)',
    back: 'Zurück',
    groupByThread: 'Nach Konversation gruppieren',
    groupDisabledInSearch:
        'Suchtreffer werden einzeln gezeigt — sie stammen aus verschiedenen Ordnern und lassen sich nicht zu Konversationen bündeln',
    loading: 'Laden...',
    countMessages: (n, gruppiert) => `${n} ${gruppiert ? 'Konversationen' : 'E-Mails'}`,
    countHits: (gezeigt, gefunden, ordner) =>
        `${gezeigt}${gefunden > gezeigt ? ` von ${gefunden}` : ''} Treffer${ordner > 1 ? ` in ${ordner} Ordnern` : ''}`,
    selected: (n) => `${n} ausgewählt`,
    alsoAffects: (n) => `${n} Nachrichten`,
    markRead: 'Gelesen',
    markUnread: 'Ungelesen',
    archive: 'Archivieren',
    archiveTitle: (n) => `${n} Nachricht(en) archivieren`,
    move: 'Verschieben',
    moveTitle: 'In Ordner verschieben',
    delete: 'Löschen',
    clearSelection: 'Auswahl aufheben',
};

/**
 * Die Werkzeugleiste an einer Mail — eigene Verben und die der Nachbarsysteme.
 *
 * Die Saetze fuer „niemand hat geantwortet" stehen hier und nicht in der
 * Komponente, weil sie das Wichtigste an dieser Leiste sind: Eine leere Leiste
 * heisst „nichts zu tun", und das ist etwas anderes als „die Verwaltung
 * antwortet gerade nicht". Wer den Unterschied nicht liest, legt sein Ticket
 * ein zweites Mal an.
 */
const werkzeuge: MailWerkzeugleisteLabels = {
    titel: 'Werkzeuge',
    leer: 'Für diese Nachricht ist nichts zu tun.',
    laedt: 'Werkzeuge werden geladen …',
    unerreichbar: (systeme) =>
        systeme.length === 1
            ? `${systeme[0]} antwortet nicht — was dort zu dieser Mail gehört, fehlt in dieser Liste.`
            : `${systeme.join(', ')} antworten nicht — was dort zu dieser Mail gehört, fehlt in dieser Liste.`,
    gescheitert: (verben) =>
        verben.length === 1
            ? `„${verben[0]}" konnte nicht geladen werden.`
            : `${verben.length} Werkzeuge konnten nicht geladen werden: ${verben.join(', ')}.`,
    nichtGefragt: 'Die Werkzeuge konnten nicht abgefragt werden.',
    laeuft: 'Wird ausgeführt …',
    nichtZeichenbar: (verben) =>
        verben.length === 1
            ? `„${verben[0]}" braucht eine Angabe, die diese Ansicht nicht erfassen kann.`
            : `${verben.length} Werkzeuge brauchen Angaben, die diese Ansicht nicht erfassen kann: ${verben.join(', ')}.`,
    bitteWaehlen: 'Bitte wählen …',
};

/**
 * Die Vorgaben, gebuendelt.
 *
 * Ein Objekt und nicht sechs Exporte: Ein Produkt schreibt
 * `germanLabels.list` und sieht am Namen, dass es eine Vorgabe nimmt — bei
 * einem freistehenden `listLabels` saehe es wie seine eigene Festlegung aus.
 */
export const germanLabels = {
    list,
    conversation,
    view,
    folders,
    header,
    toolbar,
    werkzeuge,
} as const;
