import type { MailboxHeaderLabels } from './MailboxHeader';
import type { MailConversationPaneLabels } from './MailConversationPane';
import type { MailFolderManagerLabels } from './MailFolderManager';
import type { MailListToolbarLabels } from './MailListToolbar';
import type { MailMessageListLabels } from './MailMessageList';
import type { MailMessageViewLabels } from './MailMessageView';
import type { MailWerkzeugleisteLabels } from './MailWerkzeugleiste';
import { mailboxBrowserLabels } from './MailboxBrowser';

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

/**
 * Die Liste der Nachrichten.
 *
 * ## Fünf Texte wurden am 29.10.2026 genauer (#9116)
 *
 * Sie standen so im Projekt-Manager, der sie bewusst ausführlicher gefasst
 * hatte: *„Der Manager sagt es genauer, weil er ein Archiv hat. Beim
 * Zusammenlegen darf nichts wegfallen: Wer mehr weiss, sagt mehr."*
 *
 * Beim Zurückführen seiner Seite auf die gemeinsame Fläche gab es zwei Wege.
 * Der eine: der Fläche Text-Überschreibungen beibringen. Das wäre genau das
 * Auseinanderlaufen zurück, das die Zusammenlegung abschafft — vier Produkte,
 * vier Wortlaute, und niemand weiß mehr, welcher der gemeinte ist.
 *
 * Der andere, und der ist es geworden: Die genauere Fassung gilt überall.
 * Sie ist in jedem Produkt WAHR — „liegt im Ordner ‚Gesendet'" stimmt auch im
 * CRM —, und wo es kein Archiv gibt, erscheint der Archiv-Text ohnehin nie.
 * Betroffen: `outboundBadgeTitle`, `unreadCountTitle`, `archivedTitle` (hier
 * und in der Leseansicht) und `archiveConversation`.
 */
const list: MailMessageListLabels = {
    unread: 'Ungelesen',
    outboundSender: 'Ihre Antwort',
    outboundBadge: 'gesendet',
    outboundBadgeTitle: 'Aus dem Postfach gesendet — liegt im Ordner „Gesendet"',
    expandThread: 'Konversation aufklappen',
    collapseThread: 'Konversation zuklappen',
    newest: 'Neueste',
    newestTitle: 'Die jüngste Nachricht dieser Konversation',
    unreadCount: (anzahl) => `${anzahl} neu`,
    unreadCountTitle: (anzahl, gesamt) => `${anzahl} von ${gesamt} Nachrichten dieser Konversation ungelesen`,
    archived: 'Archiv',
    archivedTitle: 'Aus dem Archiv der Anwendung — liegt nicht mehr im Postfach',
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
    archivedTitle: 'Aus dem Archiv der Anwendung — diese Nachricht liegt nicht mehr im Postfach',
    editDraft: 'Weiter bearbeiten',
    reply: 'Antworten',
    replyAll: 'Allen antworten',
    forward: 'Weiterleiten',
    markRead: 'Als gelesen markieren',
    markUnread: 'Als ungelesen markieren',
    flag: 'Markieren',
    unflag: 'Markierung entfernen',
    archive: 'Archivieren',
    archiveConversation: (anzahl) => `Ganze Konversation archivieren (${anzahl} Nachrichten)`,
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
    mineOnly: 'Nur mir zugewiesene zeigen',
    allMail: 'Alle E-Mails zeigen',
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
    /**
     * Die Woerter des Rahmens — die Umschaltung zwischen Ordnern, Liste und
     * Nachricht, die es nur unterhalb von `md` gibt.
     *
     * Anders als die uebrigen Flaechen nimmt der Rahmen seine Texte NICHT als
     * Pflichtfeld, sondern bringt sie als Vorgabe schon selbst mit — sonst
     * haette der mobile Rahmen (#1079) in jedem der vier Produkte eine Zeile
     * gekostet, und waere so lange kaputt geblieben, wie eines sie nicht hat.
     *
     * Hier steht er trotzdem: Wer die Woerter aendern will, sucht sie in
     * dieser Datei und nicht im Bauteil.
     */
    rahmen: mailboxBrowserLabels,
    list,
    conversation,
    view,
    folders,
    header,
    toolbar,
    werkzeuge,
    /**
     * Die Woerter der Zustaendigkeit.
     *
     * Standen bis zum 22.10.2026 als Literal in jedem Produkt — wortgleich, und
     * damit dreimal zu pflegen. Beim Vergleich der Mailflaechen (#8377) waren
     * sie eine der Stellen, die sich nur durch das Kopieren unterschieden
     * haetten.
     */
    wiedervorlage: {
        titel: 'Wiedervorlage (nur für mich)',
        heuteAbend: 'Heute Abend (18:00)',
        morgenFrueh: 'Morgen früh (08:00)',
        naechsteWoche: 'Nächste Woche',
        aufheben: 'Wiedervorlage aufheben',
    },
    kuerzel: {
        titel: 'Tastatur-Kürzel',
        untertitel: 'Schneller arbeiten im Postfach.',
    },
    zuweisung: {
        assign: 'Jemandem zuweisen',
        assignedTo: (name: string) => `Zuständig: ${name}`,
        unassign: 'Zuweisung aufheben',
        noUsers: 'Niemand verfügbar',
    },

    /**
     * Die Woerter des Zitats — fuer Antwort und Weiterleitung.
     *
     * Stand bis zum 22.10.2026 dreimal im Bestand, wortgleich (#8377).
     */
    zitat: {
        labels: {
            repliedOn: (datum: string, absender: string) => `Am ${datum} schrieb ${absender}:`,
            forwardHeadline: '---------- Weitergeleitete Nachricht ----------',
            from: 'Von:',
            date: 'Datum:',
            subject: 'Betreff:',
            to: 'An:',
        },
        formatDate: (iso: string) =>
            new Date(iso).toLocaleString('de-DE', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            }),
    },

    /** Der Rumpf einer Nachricht in Antwort, Weiterleitung und Vorschau. */
    rumpf: {
        empty: 'Diese E-Mail hat keinen Inhalt.',
        remoteBlocked: 'Externe Bilder wurden zum Schutz vor Tracking blockiert.',
        loadImages: 'Bilder laden',
        frameTitle: 'E-Mail Inhalt',
    },

    /** Die Woerter des Verfassen-Formulars. */
    verfassen: {
        to: 'An',
        cc: 'Kopie',
        bcc: 'Blindkopie',
        subject: 'Betreff',
        subjectPlaceholder: 'Worum geht es?',
        message: 'Nachricht',
        preview: 'Vorschau',
        attachments: 'Anhänge',
        signature: 'Signatur',
        noSignature: 'Keine',
        defaultSuffix: ' (Standard)',
        previewEmpty: 'Noch nichts geschrieben.',
        dropFiles: 'Dateien hier ablegen',
        addFile: 'Datei hinzufügen',
        chooseAccount: 'Absender wählen',
        send: 'Senden',
        sending: 'Wird gesendet …',
        saveDraft: 'Als Entwurf sichern',
        savingDraft: 'Wird gesichert …',
        toPlaceholder: 'name@firma.de',
        ccPlaceholder: 'name@firma.de',
        bccPlaceholder: 'name@firma.de',
        /**
         * Woher ein Empfaengervorschlag kommt.
         *
         * Die EINZIGE Stelle, an der die drei Produkte sich wirklich
         * unterschieden: Im CRM heisst `crm` „CRM", im Brain hiess es
         * „Adressbuch". Deshalb ist die Woerterliste des Formulars
         * ueberschreibbar — damit die Zusammenlegung nichts still umbenennt.
         */
        source: (quelle: string) => {
            switch (quelle) {
                case 'colleague':
                    return 'Kollege';
                case 'adressbuch':
                    return 'Adressbuch';
                case 'crm':
                    return 'CRM';
                default:
                    return 'Verlauf';
            }
        },
        body: {
            empty: 'Kein Inhalt',
            remoteBlocked: 'Externe Bilder wurden zum Schutz vor Tracking blockiert.',
            loadImages: 'Bilder laden',
            frameTitle: 'Vorschau',
        },
    },

    /** Die Woerter des Antwortfeldes im Postfach. */
    antwort: {
        reply: 'Antworten',
        replyAll: 'Allen antworten',
        allRecipients: 'Allen',
        senderOnly: 'Absender',
        quotedText: 'Zitierter Text',
    },

    /** Die Woerter der Weiterleitung. */
    weiterleiten: {
        forward: 'Weiterleiten',
        from: 'Von',
        forwardedMessage: 'Weitergeleitete Nachricht',
        originalAttachments: (anzahl: number, namen: string) =>
            anzahl === 1
                ? `Ein Anhang der Ursprungsnachricht geht mit: ${namen}`
                : `${anzahl} Anhänge der Ursprungsnachricht gehen mit: ${namen}`,
        forwarded: 'Weitergeleitet.',
        draftSaved: 'Entwurf gesichert.',
        draftFailed: (status: number) => `Entwurf nicht gesichert (Fehler ${status}).`,
        failed: (status: number) => `Nicht weitergeleitet (Fehler ${status}).`,
        unknownError: 'Unbekannter Fehler.',
    },

    /** Die Woerter des Editors. */
    editor: {
        bold: 'Fett (Strg+B)',
        italic: 'Kursiv (Strg+I)',
        underline: 'Unterstrichen (Strg+U)',
        strikethrough: 'Durchgestrichen',
        code: 'Code',
        heading1: 'Überschrift 1',
        heading2: 'Überschrift 2',
        heading3: 'Überschrift 3',
        bulletList: 'Aufzählung',
        orderedList: 'Nummerierte Liste',
        blockquote: 'Zitat',
        horizontalRule: 'Trennlinie',
        alignLeft: 'Linksbündig',
        alignCenter: 'Zentriert',
        alignRight: 'Rechtsbündig',
        insertLink: 'Link einfügen',
        removeLink: 'Link entfernen',
        linkPrompt: 'Adresse eingeben:',
        insertImage: 'Bild einfügen (oder ablegen bzw. einfügen)',
        dropImage: 'Bild hier ablegen',
        textColor: 'Textfarbe',
        highlight: 'Markieren',
        insertTable: 'Tabelle einfügen',
        undo: 'Rückgängig (Strg+Z)',
        redo: 'Wiederholen (Strg+Y)',
    },

} as const;
