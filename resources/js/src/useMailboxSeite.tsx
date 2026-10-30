import { useCallback, useEffect, useMemo, useState } from 'react';
import { runBulkAcrossFolders } from './bulk';
import { germanLabels } from './germanLabels';
import { httpMailboxSources, type MailboxRoutes } from './httpSources';
import type { MailFolder } from './MailFolderList';
import { httpMailboxChatSource } from './mailboxChatSource';
import { useMailboxSidePanel } from './MailboxSidePanel';
import { buildDisplayRows, type RowMessage, threadActionUids, threadOf } from './rows';
import { postCounted, postJson } from './transport';
import { useAktuelleKennung } from './useAktuelleKennung';
import { useAutoOpen } from './useAutoOpen';
import { useFolderActions } from './useFolderActions';
import { conversationRoutes, useMailConversation } from './useMailConversation';
import { useMailAssignments, type MailAssignment } from './useMailAssignments';
import { useMailboxFolders } from './useMailboxFolders';
import { useMailboxKeyboard } from './useMailboxKeyboard';
import { useMailboxList } from './useMailboxList';
import { useMailboxPreference } from './useMailboxPreference';
import { useMailboxSearch, type MailboxSearchSource } from './useMailboxSearch';
import { useMailWerkzeuge } from './useMailWerkzeuge';
import { useMessageSelection } from './useMessageSelection';
import { useOpenMessage, type OpenMessageSource } from './useOpenMessage';

/**
 * Das Postfach einer Mailseite — ALLE Haken an einer Stelle (#8316).
 *
 * ## Was hier zusammengelegt wurde
 *
 * Gemessen am 20.10.2026: Der Haken-Teil der Mailseite lag im CRM mit 637 und
 * in der Verwaltung mit 648 Zeilen — und der Unterschied war **eine
 * inhaltliche Zeile**: der Namensraum des Assistenten-Panels. Alles andere
 * waren Umbrueche, anders formulierte Kommentare und vier
 * `eslint-disable`-Zeilen.
 *
 * Die Folge solcher Kopien ist nicht Mehrarbeit, sondern Stillstand: Eine
 * Verbesserung an einer Stelle erreicht die andere nicht. Genau das ist hier
 * belegbar passiert — in den Kommentaren dieses Blocks stehen ACHT live
 * gefundene Fehler, und sie wurden einzeln in jedem Produkt nachgezogen.
 *
 * ## Warum ein Haken und keine Komponente
 *
 * Die Flaeche unterscheidet sich weiter: Das CRM zeichnet eine Kontaktleiste,
 * die Verwaltung eine Ticket- und eine Anhangleiste. Was sich NICHT
 * unterscheidet, ist der Zustand darunter. Die Fuge liegt also zwischen
 * Zustand und Darstellung — nicht zwischen Logik und Oberflaeche.
 *
 * ## Die Rueckgabe ist flach, mit Absicht
 *
 * Alle 39 Werte tragen die Namen, die sie in den Produkten schon hatten. Ein
 * Produkt holt sie mit einer Zerlegung, und sein JSX bleibt dadurch Zeile fuer
 * Zeile unveraendert — das war die Bedingung, um einen Block dieser Groesse
 * ueberhaupt verantwortlich verschieben zu koennen.
 */

/** Ein Postfach, so wie ein Produkt es hereinreicht. */
export interface MailboxSeiteKonto {
    id: number;
    email: string;
    name: string;
}

/** Die Woerter, die dieser Haken selbst braucht. */
export interface MailboxSeiteTexte {
    antwortGesendet: string;
    antwortGescheitert: string;
}

export const MAILBOX_SEITE_TEXTE: MailboxSeiteTexte = {
    antwortGesendet: 'Antwort gesendet.',
    antwortGescheitert: 'Die Antwort konnte nicht versendet werden.',
};

/**
 * Eine Quelle — oder eine Fabrik, die sie fuer ein bestimmtes Postfach baut.
 *
 * Warum es die Fabrik gibt: Eine eigene Quelle muss wissen, aus WELCHEM
 * Postfach sie holt. Das Postfach fuehrt aber dieser Haken, und die Quelle
 * geht als Option in ihn hinein — Henne und Ei.
 *
 * Der Ausweg, der sich anbietet, ist der falsche: Das Produkt haelt die
 * Kennung in einer Referenz und zieht sie in einem Effekt nach. Dann liegt
 * zwischen dem Postfachwechsel und der richtigen Kennung ein Bilddurchlauf,
 * und was in diesem Fenster passiert, geht an das ALTE Postfach. Genau das
 * war Bug #1142 und der Fehler vom 23.09.2026: Postfach wechseln, Mail
 * anklicken, nichts passiert — keine Meldung, keine Fehlerseite.
 *
 * Die Fabrik bekommt die Kennung deshalb im SELBEN Durchlauf, in dem sich das
 * Postfach aendert. Kein Effekt, kein Fenster.
 *
 * Die Fabrik selbst gehoert stabil gehalten (`useCallback`) — sonst baut sie
 * bei jedem Zeichnen eine neue Quelle, und die haengt in den Abhaengigkeiten
 * der Liste.
 */
export type QuelleOderFabrik<T> = T | ((kontoId: number | string | null) => T);

/**
 * Was ein Zeilenfilter ausser der Nachricht noch braucht.
 *
 * Gereicht wird es, statt dass der Filter sich selbst bedient: Die
 * Zustaendigkeiten fuehrt dieser Haken, und ein Filter, der sie ueber seinen
 * Rueckgabewert laese, griffe waehrend des Laufs auf eine noch nicht
 * initialisierte Bindung zu.
 */
export interface ZeilenFilterHilfen {
    /** Die Zustaendigkeit dieser Nachricht — oder `null`. */
    zuweisung: (nachricht: RowMessage) => MailAssignment | null;
}

export interface MailboxSeiteOptionen {
    /** Die Postfaecher, die dieses Produkt diesem Menschen zeigt. */
    konten: MailboxSeiteKonto[];
    /**
     * Der Namensraum des Assistenten-Panels, etwa `crm.mailbox.assistent`.
     *
     * Produktspezifisch, weil die Breite des Panels je Produkt gemerkt wird —
     * und ein gemeinsamer Schluessel hiesse, dass eine Verstellung im CRM die
     * Verwaltung mitverstellt.
     */
    namensraum: string;
    /** Wie das Produkt eine Rueckmeldung zeigt. Ueblicherweise `toast`. */
    melden: (text: string, gelungen: boolean) => void;
    labels?: Partial<MailboxSeiteTexte>;
    /**
     * Der Vorsatz, unter dem die Mail-Routen dieses Produkts liegen.
     *
     * Vorgabe `/mailbox`, weil drei der vier Produkte dort liegen und sich
     * fuer sie nichts aendern soll.
     *
     * ## Warum das eine Option sein MUSS
     *
     * Hier standen die Adressen fest verdrahtet — `/mailbox/{id}/search`,
     * `/mailbox/{id}/assignments` und zwanzig weitere. Der Projekt-Manager hat
     * seine 56 Mail-Routen aber unter `/emails/...`, und damit war dieser
     * Haken fuer ihn unbenutzbar. Das ist der Grund, warum dort eine eigene
     * Mail-Seite mit 2216 Zeilen gewachsen ist (#9116), wo CRM mit 122
     * auskommt.
     *
     * Die naheliegende Alternative waere, die 56 Routen des Managers
     * umzuziehen. Das ist eine SICHTBARE Aenderung: Lesezeichen brechen, und
     * `emails/stored/{email}` wird aus Aufgaben verlinkt. Eine Option kostet
     * dagegen nichts und niemanden.
     *
     * Ohne fuehrenden Schraegstrich am Ende: `/mailbox`, nicht `/mailbox/`.
     */
    basis?: string;
    /**
     * Eine eigene Suchquelle, falls das Produkt mehr kann als der Endpunkt.
     *
     * Der Haken baut sonst seine eigene (`{basis}/{id}/search`), und das ist
     * fuer drei der vier Produkte genau richtig.
     *
     * Der Projekt-Manager hat aber einen Volltext-Index und meldet dessen
     * Zustand in der Oberflaeche — etwas, das dieser Haken nicht kennen soll
     * und nicht kennen muss. Ohne diesen Platz waere er gezwungen, die ganze
     * Seite selbst zu bauen, nur wegen der Suche (#9116).
     *
     * `httpSources` hatte den Platz (`search?: MailboxSearchSource`) schon;
     * er kam hier nur nicht an.
     */
    suchQuelle?: QuelleOderFabrik<MailboxSearchSource<RowMessage>>;
    /**
     * Eine eigene Oeffnen-Quelle, falls das Produkt mehr oeffnen kann als ein
     * Postfach hergibt.
     *
     * Der Haken baut sonst `{basis}/{id}/message/{uid}?folder=…`, und das ist
     * fuer drei der vier Produkte genau richtig.
     *
     * Der Projekt-Manager hat ein eigenes Archiv: Eine Zeile mit
     * `source: 'stored'` hat GAR KEINE IMAP-Kennung und liegt in keinem
     * Ordner — sie kommt unter `{basis}/{id}/stored/{email}/json` aus der
     * Datenbank. Die Nachrichtenform des Pakets kennt `source` und
     * `stored_id` seit jeher ({@link RowMessage}), seine HTTP-Quelle aber
     * nicht; sie fragt immer nach der Kennung.
     *
     * Ohne diesen Platz koennte der Manager nach dem Umstieg auf die
     * gemeinsame Flaeche keine Archiv-Mail mehr oeffnen — und das ist keine
     * Randfunktion: Dort liegen die Mails an Aufgaben und die eigenen
     * gesendeten Nachrichten (#9116).
     */
    oeffnenQuelle?: QuelleOderFabrik<OpenMessageSource<RowMessage, Record<string, never>>>;
    /**
     * Ob die Liste beim ersten Zeichnen nach Konversationen gruppiert ist.
     *
     * Vorgabe `false`, wie bisher. Umschalten kann man weiterhin in der
     * Leiste; hier steht nur, womit es anfaengt.
     *
     * ## Warum das eine Option ist und nicht gemerkt wird
     *
     * Die Ordnerspalte merkt sich dieses Paket selbst, pro Browser
     * ({@link useMailboxPreference}) — mit der dort aufgeschriebenen
     * Begruendung, dass eine eingeklappte Spalte die Bequemlichkeit EINES
     * Bildschirms ist.
     *
     * Die Gruppierung ist etwas anderes: Sie entscheidet, was man liest, nicht
     * wie breit es steht. Der Projekt-Manager merkt sie deshalb seit je
     * serverseitig an der Person (`emailPreferences.groupByThread`) — sie
     * folgt ihm auf jedes Geraet.
     *
     * Dieser Haken gab sie bisher GAR NICHT weiter. Nach einem Umstieg auf
     * diese Flaeche haette die Liste also immer flach angefangen, und die
     * Wahl waere nicht etwa pro Browser, sondern ganz verloren gewesen
     * (#9116).
     *
     * Als Option statt als eigene Merkregel, damit jedes Produkt behalten
     * kann, wo es sie fuehrt: der Manager am Server, ein anderes vielleicht
     * nirgends.
     */
    initialGrouped?: boolean;
    /**
     * Was `?` tun soll — die Tastaturhilfe des Produkts.
     *
     * Das Paket bringt keine mit: Eine Hilfe ist eine Liste von Tasten in
     * einem Dialog, und der Dialog kommt aus dem UI-Kit des Produkts.
     * Fehlt die Angabe, tut `?` nichts — wie bisher.
     */
    onHilfe?: () => void;
    /**
     * Was `/` tun soll — ins Suchfeld springen.
     *
     * Auch das bleibt beim Produkt, aber aus einem anderen Grund: Der Haken
     * besitzt keinen Knoten. Ein Fokus braucht eine Referenz auf ein echtes
     * Eingabefeld, und das steht in der Leiste.
     */
    onSuchen?: () => void;
    /**
     * Einzelne Adressen anders bauen, als dieser Haken sie vorgibt.
     *
     * Wird UEBER die Vorgaben gelegt, nicht statt ihrer — wer nur `targets`
     * angibt, behaelt die uebrigen vier.
     *
     * ## Warum das nicht drei Schalter sind
     *
     * Drei Dinge fehlten dem Projekt-Manager, und jedes haette einen eigenen
     * Schalter gebraucht (#9116):
     *
     * - `targets` — die Zielordner beim Verschieben. `httpMailboxSources`
     *   kennt die Route, dieser Haken setzte sie nie; die Auswahl waere nach
     *   dem Umstieg leer geblieben.
     * - Ketten statt Nachrichten. `ListParams` traegt `grouped`, und die
     *   Vorgabe hier ignorierte es: In der Gruppierung holte sie die FLACHE
     *   Liste. Der Manager hat dafuer `/threads`.
     * - `refresh`. Traegt `ListParams` ebenfalls, wurde ebenfalls
     *   verschluckt — ein Neuladen las den Zwischenspeicher.
     *
     * Eine ueberschreibbare Angabe ist weniger API als drei Schalter und
     * deckt auch das ab, was der naechste Fall braucht.
     *
     * ## Ein Befund am Rande, der hier NICHT geloest wird
     *
     * CRM, Verwaltung und AI Brain haben gar keinen Ketten-Endpunkt
     * (`routes/web.php`: null Treffer fuer `threads`). Der Gruppieren-Schalter
     * in der Leiste verspricht dort also etwas, das niemand liefern kann, und
     * zeigt still die flache Liste. Das ist ein eigener Fehler und keine
     * Nebenwirkung dieser Option — die Vorgabe bleibt deshalb unveraendert
     * `messages`, damit dort nichts neu bricht.
     *
     * ## ⚠️ STABIL hereingeben, nicht als frisches Objekt
     *
     * Dieser Wert steht in der Abhaengigkeitsliste der Quellen. Ein bei jedem
     * Zeichnen neu gebautes Literal stellt damit JEDEN laufenden Abruf neu —
     * dieselbe Falle, an der die Quelle schon einmal haengengeblieben ist
     * (23.09.2026, siehe `useAktuelleKennung`).
     *
     * Also in ein `useMemo` mit leerer Abhaengigkeitsliste, so wie die
     * Produkte es mit ihren Chat- und Empfaengerquellen auch tun.
     */
    routen?: Partial<MailboxRoutes>;
    /**
     * Zeilen, die die Liste ueberspringt — zusaetzlich zum Schlummern.
     *
     * Der Projekt-Manager hat einen Filter fuer die eigenen Zustaendigkeiten,
     * und der braucht Wissen, das nur er hat: wem eine Nachricht zugewiesen
     * ist und wer gerade angemeldet ist.
     *
     * Ohne diesen Platz waere der Filter nach dem Umstieg ohne Wirkung
     * gewesen — der Schalter haette sich weiter umlegen lassen und nichts
     * getan.
     */
    zeilenFilter?: (nachricht: RowMessage, hilfen: ZeilenFilterHilfen) => boolean;
    /**
     * Welches Postfach beim ersten Zeichnen offen ist — in aller Regel das aus
     * `?account=` der Adresse.
     *
     * Ohne Angabe, oder wenn es dieses Postfach nicht gibt: das erste. Und
     * zwar ohne Meldung. Eine unbekannte Nummer in einer Adresse ist ein alter
     * Verweis, kein Fehler des Benutzers; ihn mit einer Stoerung zu begruessen
     * hiesse, ihm etwas vorzuwerfen, das er nicht getan hat.
     *
     * Im Manager erzeugt `NotificationService` solche Verweise an drei
     * Stellen (`/emails?account={id}`), und AI Brain zeigt sie im Posteingang.
     * Ohne diese Option oeffnete so ein Verweis das erste Postfach statt des
     * gemeinten — der Klick waere umsonst.
     */
    anfangsKonto?: number | string | null;
}

export function useMailboxSeite({
    konten,
    namensraum,
    melden,
    labels,
    basis = '/mailbox',
    suchQuelle,
    oeffnenQuelle,
    initialGrouped = false,
    onHilfe,
    onSuchen,
    routen,
    zeilenFilter,
    anfangsKonto = null,
}: MailboxSeiteOptionen) {
    const texte = { ...MAILBOX_SEITE_TEXTE, ...labels };

    /*
     * Nur beim ERSTEN Zeichnen gelesen (die Form mit der Funktion). Spaeter
     * entscheidet der Waehler, und eine Adresse, die sich inzwischen geaendert
     * hat, darf ihn nicht ueberstimmen.
     *
     * Verglichen wird als Zeichenkette: Aus der Adresse kommt sie als solche,
     * aus den Konten als Zahl, und `'7' === 7` ist falsch.
     */
    const [konto, setKontoRoh] = useState<MailboxSeiteKonto | null>(
        () => konten.find((k) => String(k.id) === String(anfangsKonto)) ?? konten[0] ?? null,
    );

    /**
     * Postfach wechseln — und die Adresse mitfuehren.
     *
     * `replaceState`, nicht `pushState`: Sonst legt jeder Wechsel einen
     * Eintrag im Zurueck-Verlauf an, und wer sich durch vier Postfaecher
     * geklickt hat, braucht vier Mal „zurueck", um dahin zu kommen, wo er
     * herkam.
     *
     * Erstes Argument ist der VORHANDENE Zustand, nicht `{}`. An
     * `history.state` haengt bei Inertia das ganze Page-Objekt; ein frisches
     * Objekt loescht es, und der Zurueck-Knopf holt die Seite dann per XHR neu
     * statt den verlassenen Stand zu zeigen. Das ist Bug #1129 im Manager,
     * dort dreimal passiert — es soll nicht hier zum vierten Mal entstehen.
     *
     * Und alles in `try`: Firefox wirft bei zu vielen Aufrufen
     * NS_ERROR_ILLEGAL_VALUE. Ein Postfachwechsel, der an der Adresszeile
     * scheitert, ist trotzdem ein Postfachwechsel.
     */
    const setKonto = useCallback((naechstes: MailboxSeiteKonto | null) => {
        setKontoRoh(naechstes);

        if (typeof window === 'undefined' || !naechstes) {
            return;
        }

        try {
            const adresse = new URL(window.location.href);

            if (adresse.searchParams.get('account') === String(naechstes.id)) {
                return;
            }

            adresse.searchParams.set('account', String(naechstes.id));
            window.history.replaceState(window.history.state, '', adresse.toString());
        } catch {
            // Die Adresse ist eine Bequemlichkeit, kein Teil der Arbeit.
        }
    }, []);
    const [ordnerPfad, setOrdnerPfad] = useState('INBOX');
    const [fehler, setFehler] = useState<string | null>(null);

    /**
     * Wo die Postfach-Daten herkommen — mehr sagt das Produkt dazu nicht. Die
     * Anfrage selbst, was eine Absage ist und was ein fehlender Schluessel
     * bedeutet, liegt im Paket.
     */
    /**
     * Das aktuelle Postfach — als Referenz, nicht als eingefangener Wert.
     *
     * ## Der Fehler, den das behebt
     *
     * Die Quellen unten stehen in einem `useMemo` mit leerer
     * Abhaengigkeitsliste, damit sie stabil bleiben. `accountId: () =>
     * konto?.id` schloss dabei ueber `konto` — und hielt damit fuer immer das
     * ERSTE Postfach fest.
     *
     * Die Liste merkte davon nichts: Sie bekommt die Kennung bei jedem Laden
     * ausdruecklich mitgegeben. Das OEFFNEN einer Nachricht nicht — es fragt
     * die Quelle. Wer also das Postfach wechselte und dann eine Mail anklickte,
     * schickte deren Kennung an das alte MailboxSeiteKonto. Am 23.09.2026 in AI Brain live
     * nachgestellt: `/mailbox/8/message/2943` fuer eine Nachricht aus MailboxSeiteKonto 11.
     * (Der Vorsatz `/mailbox` ist die Vorgabe und ueber `basis` einstellbar.)
     * Es passierte schlicht nichts — kein Fehler, keine Meldung, die Mail ging
     * nicht auf.
     *
     * Eine Referenz ist hier richtig und nicht faul: Die Quelle SOLL stabil
     * bleiben (sonst stellt sich der Abruf bei jedem Zeichnen neu), und sie
     * soll trotzdem den aktuellen Wert lesen. Genau dafuer gibt es sie.
     */
    /*
     * Die Kennung des aktuellen Postfachs, stabil abfragbar.
     *
     * Hier stand bis zum 20.10.2026 eine Referenz samt nachfuehrendem
     * Effekt — in VIER Produkten, viermal dieselben acht Zeilen mit
     * demselben zwanzigzeiligen Kommentar. Jetzt im Paket, mit der
     * Begruendung an EINER Stelle und einer Mutationsprobe dahinter.
     */
    const kennung = useAktuelleKennung(konto);

    const quellen = useMemo(
        () =>
            httpMailboxSources<RowMessage, Record<string, never>>({
                accountId: kennung,
                routes: {
                    // Die eigenen des Produkts liegen UEBER diesen, siehe `routen`.
                    ...routen,
                    folders: routen?.folders ?? ((id) => `${basis}/${id}/folders`),
                    messages:
                        routen?.messages ??
                        ((id, { folder, page }) => `${basis}/${id}/messages?folder=${encodeURIComponent(folder)}&page=${page}`),
                    message:
                        routen?.message ??
                        ((id, { uid, folder }) => `${basis}/${id}/message/${uid}?folder=${encodeURIComponent(folder)}`),
                    search: (id, p) => {
                        const teile = new URLSearchParams({ folder: p.folder });

                        if (p.query) teile.set('q', p.query);
                        if (p.from) teile.set('from', p.from);
                        if (p.subject) teile.set('subject', p.subject);
                        if (p.since) teile.set('since', p.since);
                        if (p.unseen) teile.set('unseen', '1');
                        // Über alle Ordner ist die Vorgabe des Hakens — der
                        // Endpunkt erfährt es über `scope`.
                        if (p.allFolders) teile.set('scope', 'all');

                        return `${basis}/${id}/search?${teile.toString()}`;
                    },
                },
            }),
        /*
         * `kennung` steht in der Liste, und das ist der Gewinn der
         * Umstellung: Der Abfrager ist stabil (siehe `useAktuelleKennung`),
         * also bleibt die Quelle stabil UND die Liste ist vollstaendig.
         *
         * Vorher stand hier `[]` mit einem `eslint-disable` fuer
         * `exhaustive-deps` — und genau diese Unterdrueckung hat den Fehler
         * vom 23.09.2026 verdeckt, bei dem die Quelle fuer immer das erste
         * Postfach festhielt.
         *
         * `basis` steht aus demselben Grund dabei: Seit v0.155.0 bauen diese
         * Adressen damit, und eine unvollstaendige Liste ist genau die Form
         * des Fehlers von damals — nur ein Feld weiter. Dass der Wert je
         * Produkt konstant ist, macht die Auslassung nicht richtig, sondern
         * nur folgenlos, und folgenlos hoert irgendwann auf.
         */
        [kennung, basis, routen],
    );

    // Stabil halten: Die Quelle haengt am Takt des Chats — eine bei jedem
    // Zeichnen neue Quelle wuerde ihn jedes Mal neu stellen.
    const chatQuelle = useMemo(
        () =>
            httpMailboxChatSource({
                url: (id) => `${basis}/${id}/ai-chat`,
                csrfToken: document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            }),
        // `basis` seit v0.155.0 — dieselbe Begruendung wie bei `quellen`.
        [basis],
    );

    const assistent = useMailboxSidePanel(namensraum);

    /*
     * Die eigenen Quellen aufloesen — mit der Kennung, die in DIESEM Durchlauf
     * gilt. `konto` ist der Zustand oben in diesem Haken; wer hier liest,
     * liest nicht den Stand von vorhin.
     *
     * Die Objektform bleibt gueltig und unveraendert: CRM, Verwaltung und AI
     * Brain reichen gar keine eigene Quelle herein, der Manager eine Fabrik.
     */
    const eigeneOeffnenQuelle = useMemo(
        () => (typeof oeffnenQuelle === 'function' ? oeffnenQuelle(konto?.id ?? null) : oeffnenQuelle),
        [oeffnenQuelle, konto?.id],
    );

    const eigeneSuchQuelle = useMemo(
        () => (typeof suchQuelle === 'function' ? suchQuelle(konto?.id ?? null) : suchQuelle),
        [suchQuelle, konto?.id],
    );

    const ordner = useMailboxFolders({ source: quellen.folders });
    const liste = useMailboxList<RowMessage>({ source: quellen.messages, initialGrouped });
    // Die eigene des Produkts, sonst die des Hakens.
    const offene = useOpenMessage<RowMessage, Record<string, never>>({ source: eigeneOeffnenQuelle ?? quellen.message });
    const suche = useMailboxSearch<RowMessage>({
        // Die eigene des Produkts, sonst die des Hakens.
        source: eigeneSuchQuelle ?? quellen.search!,
        folder: ordnerPfad,
        onResults: (zeilen, gesamt) => liste.showRows(zeilen, gesamt),
        // Zurück zur normalen Liste — der Ordner, der offen war.
        onCleared: () => liste.load({ accountId: konto?.id ?? '', folder: ordnerPfad, page: 1 }),
        onFailure: setFehler,
    });

    const auswahl = useMessageSelection<RowMessage>({
        threads: liste.threads,
        grouped: liste.grouped,
        isSearchMode: suche.isSearchMode,
    });

    // Die Liste, wie sie gezeichnet wird: flach oder als Konversationen. Kommt
    // aus dem Paket, damit „was ist eine Kette" überall dasselbe heißt —
    // Suchtreffer bleiben immer flach, denn sie kommen aus vielen Ordnern.
    /* Geschlummerte Ketten werden beim Zeichnen uebersprungen — nicht beim
       Laden. So steht die Nachricht nach Ablauf der Zeit wieder da, ohne dass
       irgendwo etwas umgeschrieben werden muesste. */
    const sichtbareZeilen = (rohZeilen: typeof zeilen) =>
        schlummernde.length === 0
            ? rohZeilen
            : rohZeilen.filter(
                  (z) =>
                      !schlummernde.some(
                          (s) => (s.message_id && s.message_id === z.msg.message_id) || s.thread_id === z.msg.message_id,
                      ),
              );

    /*
     * Die Zustaendigkeiten stehen hier oben, weil der Zeilenfilter des
     * Produkts sie braucht (#9116). Vorher standen sie weiter unten — und
     * ein Filter, der sie lesen wollte, haette auf `seite` zugreifen
     * muessen, also auf den Rueckgabewert DIESES Hakens. Das ist waehrend
     * seines eigenen Laufs ein Zugriff vor der Initialisierung.
     */
    const zuweisungsQuelle = useMemo(
        () => ({
            users: async (id: number | string, signal: AbortSignal) =>
                (await (await fetch(`${basis}/${id}/assignable-users`, { headers: { Accept: 'application/json' }, signal })).json()).users ?? [],
            // Die Antwort traegt seit dem 19.10.2026 mit, OB sie eine ist:
            // „nicht geholt" und „niemand zustaendig" ergeben beide eine leere
            // Liste, und nur eine davon ist eine Aussage.
            load: async (id: number | string, signal: AbortSignal) => {
                const antwort = await (await fetch(`${basis}/${id}/assignments`, { headers: { Accept: 'application/json' }, signal })).json();

                return {
                    assignments: antwort.assignments ?? [],
                    // Fehlt die Angabe, ist die Gegenstelle aelter als diese
                    // Oberflaeche. Dann gilt die Liste als Aussage — so war es
                    // vorher, und ein erfundener Ausfall waere schlimmer.
                    reachable: antwort.reachable ?? true,
                };
            },
            assign: async (id: number | string, nachricht: RowMessage, userId: number) =>
                postJson(
                    `${basis}/${id}/assignments`,
                    { message_id: nachricht.message_id ?? '', assigned_to_user_id: userId },
                    { csrfToken: csrf },
                ),
            // Ein echtes DELETE, keine Umdeutung ueber `_method`: Laravel
            // liest das nur aus Formulardaten, nicht aus einem JSON-Rumpf —
            // die Anfrage waere auf 405 gelaufen.
            unassign: async (id: number | string, nachricht: RowMessage) => {
                const antwort = await fetch(`${basis}/${id}/assignments`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ message_id: nachricht.message_id ?? '' }),
                });

                return antwort.ok;
            },
        }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    const zuweisungen = useMailAssignments({
        accountId: konto?.id ?? null,
        source: zuweisungsQuelle,
        onFailure: setFehler,
    });

    const zeilenFilterMitHilfen = useMemo(
        () => (zeilenFilter ? (nachricht: RowMessage) => zeilenFilter(nachricht, { zuweisung: zuweisungen.fuer }) : undefined),
        [zeilenFilter, zuweisungen.fuer],
    );

    /* eslint-disable-next-line react-hooks/preserve-manual-memoization -- `zeilen` haengt an einer Kette von Ableitungen, die der Compiler nicht nachvollzieht; ohne das Memo wird die ganze Liste bei jedem Tastendruck neu gebaut. */
    const zeilen = useMemo(
        () =>
            buildDisplayRows<RowMessage>({
                messages: liste.messages,
                threads: liste.threads,
                groupByThread: liste.grouped,
                isSearchMode: suche.isSearchMode,
                expandedThreads: auswahl.expanded,
                // Der zusaetzliche Filter des Produkts, etwa die eigenen Zustaendigkeiten.
                filter: zeilenFilterMitHilfen,
                // Eine Kettennachricht trägt weniger als eine Listenzeile —
                // was fehlt, wird hier ergänzt statt im Paket geraten.
                toRow: (m) => ({
                    uid: m.uid ?? 0,
                    message_id: m.message_id,
                    subject: m.subject ?? '(Kein Betreff)',
                    from_address: m.from_address ?? '',
                    from_name: m.from_name ?? '',
                    date: m.date ?? '',
                    has_attachments: false,
                    attachment_count: 0,
                    is_read: m.is_read ?? true,
                    is_flagged: false,
                    preview: '',
                    source: m.source,
                    stored_id: m.stored_id,
                }),
            }),
        /*
         * `zeilenFilterMitHilfen` steht in der Liste, und das ist der ganze
         * Punkt: Bis zum 30.10.2026 fehlte es. Wer „nur mir zugewiesene"
         * umlegte, bekam die Zeilen von vorher — gemessen: zwei Zeilen vorher,
         * zwei Zeilen nachher, obwohl der Filter nur eine durchlaesst. Der
         * Schalter liess sich bedienen und tat nichts.
         */
        [liste.messages, liste.threads, liste.grouped, suche.isSearchMode, auswahl.expanded, zeilenFilterMitHilfen],
    );

    const [filterOffen, setFilterOffen] = useState(false);
    /* Wer die Ordnerspalte zuklappt, hatte sie beim naechsten Aufruf wieder
       vor sich. Der Schalter merkt sich das jetzt — pro Browser, denn auf
       einem anderen Geraet passt die Entscheidung ohnehin nicht. */
    const [ordnerSichtbar, setOrdnerSichtbar] = useMailboxPreference('mailbox.ordnerspalte', true);
    const [beschaeftigt, setBeschaeftigt] = useState(false);

    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    /*
     * Die Werkzeuge der ANDEREN Systeme an dieser Mail (#8313/#8316).
     *
     * Bis zum 20.10.2026 war dieser Abruf nirgends verdrahtet: Verzeichnis,
     * Endpunkte und Verben standen in allen vier Produkten, Tests gruen — und
     * kein Frontend fragte sie ab. Fuer den Menschen davor hatte sich nichts
     * geaendert.
     *
     * Der Gewinn ist der ZUSTAND, nicht der Knopf: Wer hier sieht, dass die
     * Mail in der Verwaltung schon Ticket #7 ist, legt kein zweites an.
     */
    const werkzeugNachricht = offene.message as never as {
        message_id?: string | null;
        in_reply_to?: string | null;
        references?: string | null;
        subject?: string | null;
        from_address?: string | null;
    } | null;

    const werkzeuge = useMailWerkzeuge({
        endpoint: konto ? `${basis}/${konto.id}/tools` : null,
        ausloesenEndpoint: konto ? `${basis}/${konto.id}/tools/run` : null,
        nachricht: werkzeugNachricht,
        csrfToken: csrf,
    });

    /**
     * Eine Sammelaktion. Was sie bedeutet, entscheidet das Paket — hier steht
     * nur die Adresse des Endpunkts.
     *
     * `runBulkAcrossFolders` gruppiert vorher nach Ordner: Eine Auswahl aus
     * Suchtreffern liegt in mehreren Ordnern, und IMAP kennt eine Kennung nur
     * innerhalb ihres eigenen. Ein Aufruf mit dem gerade offenen Ordner traefe
     * dort nichts — und meldete trotzdem Erfolg.
     */
    const sammelAktion = async (aktion: string, zielOrdner?: string) => {
        if (!konto || auswahl.targets.length === 0) {
            return;
        }

        setBeschaeftigt(true);

        const urteil = await runBulkAcrossFolders({
            uids: auswahl.targets,
            currentFolder: ordnerPfad,
            targetFolder: zielOrdner ?? null,
            folderOf: (uid) => zeilen.find((z) => z.msg.uid === uid)?.msg.folder ?? ordnerPfad,
            send: (ordnerName, uids) =>
                postCounted(
                    `${basis}/${konto.id}/bulk`,
                    { folder: ordnerName, action: aktion, uids, ...(zielOrdner ? { target_folder: zielOrdner } : {}) },
                    { csrfToken: csrf },
                ),
        });

        setBeschaeftigt(false);
        auswahl.clear();

        // Ein Teilerfolg ist kein Erfolg. Wer nichts hoert, haelt alles fuer
        // erledigt — und sieht erst beim naechsten Laden, dass die Haelfte
        // noch liegt.
        if (urteil.kind === 'unreachable') {
            setFehler('Das Postfach ist gerade nicht erreichbar — es wurde nichts geändert.');
        } else if (urteil.kind === 'none') {
            setFehler('Keine der ausgewählten Nachrichten konnte geändert werden.');
        } else if (urteil.kind === 'partial') {
            setFehler(`${urteil.processed} geändert, ${urteil.failed} nicht — die übrigen liegen noch im Ordner.`);
        } else {
            setFehler(null);
        }

        await liste.load({ accountId: konto.id, folder: ordnerPfad, page: liste.page });
    };

    /**
     * Ordner anlegen, umbenennen, loeschen — Zustand, Wirkung und Masken
     * kommen aus dem Paket. Hier steht nur die Adresse und was danach neu zu
     * laden ist.
     */
    const ordnerVerwaltung = useFolderActions<MailFolder>({
        accountId: konto?.id ?? null,
        endpoint: (id) => `${basis}/${id}/folders`,
        csrfToken: csrf,
        selectedFolder: ordnerPfad,
        labels: {
            created: 'Ordner angelegt.',
            renamed: 'Ordner umbenannt.',
            deleted: 'Ordner gelöscht.',
            failed: 'Ordner-Aktion fehlgeschlagen.',
        },
        onChanged: async () => {
            if (konto) {
                await ordner.load(konto.id);
            }
        },
        // Ohne das bliebe die Nachrichtenliste auf einem Ordner stehen, den es
        // nicht mehr gibt — und jeder Klick darin liefe ins Leere.
        onDeletedCurrent: async () => {
            setOrdnerPfad('INBOX');
            offene.close();

            if (konto) {
                await liste.load({ accountId: konto.id, folder: 'INBOX', page: 1 });
            }
        },
        onMessage: (art, text) => setFehler(art === 'fehler' ? text : null),
    });

    /**
     * Wer sich um welche Unterhaltung kuemmert. Der Mechanismus liegt im
     * Paket; hier stehen die Adressen und — ueber den Endpunkt — wer dieses
     * System kennt.
     */

    /**
     * Was man mit der geoeffneten Nachricht tun kann.
     *
     * Bis zum 22.09.2026 stand hier gar nichts: Die Ansicht zeigte die
     * Nachricht und liess sich nicht dazu bewegen, etwas mit ihr zu tun. Die
     * Endpunkte gab es da schon — es fehlte nur die Verdrahtung.
     *
     * Jede Aktion laedt danach die Liste neu. Das ist teurer als die Zeile
     * einzeln nachzuziehen, aber es kann nicht auseinanderlaufen: Archivieren
     * nimmt die Nachricht aus dem Ordner, und eine Liste, die sie noch zeigt,
     * behauptet etwas Falsches.
     */
    const [nachrichtBeschaeftigt, setNachrichtBeschaeftigt] = useState(false);

    /*
     * Antworten passiert seit dem 13.10.2026 HIER und nicht mehr auf der
     * Verfassen-Seite — dieselbe Komponente wie im Manager und in AI Brain.
     */
    const [antwortOffen, setAntwortOffen] = useState(false);
    const [weiterleitenOffen, setWeiterleitenOffen] = useState(false);
    const [antwortAnAlle, setAntwortAnAlle] = useState(false);
    const [sendetAntwort, setSendetAntwort] = useState(false);

    const amGeoeffneten = async (tun: () => Promise<boolean>, schliessen = false) => {
        if (!konto || nachrichtBeschaeftigt) {
            return;
        }

        setNachrichtBeschaeftigt(true);

        try {
            if (!(await tun())) {
                setFehler('Die Aktion konnte nicht ausgeführt werden.');

                return;
            }

            if (schliessen) {
                offene.close();
            }

            await liste.load({ accountId: konto.id, folder: ordnerPfad, page: liste.page, refresh: true });
        } finally {
            setNachrichtBeschaeftigt(false);
        }
    };

    const verlauf = useMailConversation({
        routes: conversationRoutes('/mailbox'),
        accountId: konto?.id ?? null,
        thread: ((offene.message as { thread_id?: string | null } | null)?.thread_id ?? '') || '',
    });

    const offeneKennung = () => (offene.message as { uid?: number | string } | null)?.uid ?? '';

    /**
     * Was ein Klick auf „Archivieren" tatsaechlich verschiebt.
     *
     * Bis zum 23.09.2026 war es genau eine Nachricht — im Projekt-Manager
     * dagegen die ganze Kette. Dieselbe Oberflaeche, zwei Bedeutungen: Wer
     * hier eine gelesene Konversation wegraeumte, hatte sie eine Antwort
     * spaeter wieder im Posteingang und hielt das Archivieren fuer kaputt.
     *
     * Die Auswahl macht das Paket (`threadOf`/`threadActionUids`) — samt der
     * beiden Faelle, die man leicht uebersieht: ausserhalb der
     * Konversationsansicht und in der Suche gibt es keine Kette, und eigene
     * gespeicherte Antworten liegen im Gesendet-Ordner und haben hier gar
     * keine Kennung.
     *
     * Verschoben wird trotzdem in EINEM Aufruf ueber `bulk` — je Nachricht neu
     * anzumelden ist der Weg in die Drosselung von O365.
     */
    const geoeffneteKette = useMemo(
        () => threadOf<RowMessage>(liste.threads, offeneKennung() || null, liste.grouped, suche.isSearchMode),
        // eslint-disable-next-line react-hooks/exhaustive-deps -- offeneKennung() liest offene.message
        [liste.threads, liste.grouped, suche.isSearchMode, offene.message],
    );

    const archivKennungen = useMemo(
        () => threadActionUids<RowMessage>(geoeffneteKette, offeneKennung() || null),
        // eslint-disable-next-line react-hooks/exhaustive-deps -- dito
        [geoeffneteKette, offene.message],
    );

    /**
     * Schlummern — „nicht jetzt, erinnere mich wieder ab dann".
     *
     * Pro Person: Wer in einem Gruppenpostfach etwas fuer ALLE verschwinden
     * liesse, nimmt der Kollegin eine Nachricht weg, von der sie nichts weiss.
     *
     * Die geschlummerten Ketten kommen einmal je Postfach und werden hier
     * beim Zeichnen uebersprungen. Es gibt keinen Lauf, der etwas zurueckholt:
     * Laeuft die Zeit ab, steht die Nachricht beim naechsten Laden wieder da.
     */
    const [schlummernde, setSchlummernde] = useState<{ thread_id: string; message_id: string | null }[]>([]);

    useEffect(() => {
        if (!konto) {
            /* eslint-disable-next-line react-hooks/set-state-in-effect -- Abgeleiteter Zustand, der beim Wechsel zurueckgesetzt werden MUSS — sonst zeigt die Ansicht fuer einen Augenblick die Daten des vorigen Postfachs. */
            setSchlummernde([]);

            return;
        }

        const abbruch = new AbortController();

        void (async () => {
            try {
                const antwort = await fetch(`${basis}/${konto.id}/snoozes`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: abbruch.signal,
                });

                setSchlummernde(antwort.ok ? ((await antwort.json()).snoozes ?? []) : []);
            } catch {
                // Ohne die Liste wird nichts uebersprungen. Das ist die
                // richtige Richtung: lieber eine Nachricht zu viel zeigen als
                // eine zu wenig.
            }
        })();

        return () => abbruch.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [konto?.id]);

    const schlummern = async (bis: Date) => {
        const m = offene.message as { message_id?: string } | null;

        if (!konto || !m?.message_id) {
            return;
        }

        const ok = await postJson(
            `${basis}/${konto.id}/snoozes`,
            { message_id: m.message_id, until: bis.toISOString() },
            { csrfToken: csrf },
        );

        if (!ok) {
            setFehler('Diese Nachricht kann nicht schlummern.');

            return;
        }

        offene.close();
        const antwort = await fetch(`${basis}/${konto.id}/snoozes`, { headers: { Accept: 'application/json' } });
        setSchlummernde(antwort.ok ? ((await antwort.json()).snoozes ?? []) : []);
        await liste.load({ accountId: konto.id, folder: ordnerPfad, page: liste.page, refresh: true });
    };

    /**
     * Die fertige Antwort aus dem Panel auf den Weg bringen — dieselbe Route
     * wie die Verfassen-Seite (`HandlesSending` aus dem Paket). `in_reply_to`
     * und `references` muessen mit, sonst beginnt die Antwort beim Empfaenger
     * einen neuen Strang.
     */
    const antwortSenden = async (
        rumpfHtml: string,
        an: { email: string; name?: string | null }[],
        kopie: { email: string; name?: string | null }[],
        betreff: string,
        anhaenge: File[],
        zitatHtml: string,
    ) => {
        const m = offene.message as never as { message_id?: string | null; references?: string | null } | null;

        if (!konto || an.length === 0 || sendetAntwort) {
            return;
        }

        setSendetAntwort(true);

        try {
            const inhalt = `${rumpfHtml}${zitatHtml}`;

            /*
             * FormData und nicht JSON: Das Antwortfeld erlaubt Anhaenge, und
             * Dateien passen in kein JSON. Bis zum 14.10.2026 ging die Antwort
             * als JSON hinaus — die angehaengte Datei blieb still liegen, und
             * die Meldung sagte trotzdem „gesendet".
             */
            const nutzlast = new FormData();

            an.forEach((a, i) => {
                nutzlast.append(`to[${i}][email]`, a.email);
                nutzlast.append(`to[${i}][name]`, a.name ?? '');
            });
            kopie.forEach((a, i) => {
                nutzlast.append(`cc[${i}][email]`, a.email);
                nutzlast.append(`cc[${i}][name]`, a.name ?? '');
            });
            nutzlast.append('subject', betreff);
            nutzlast.append('body_html', inhalt);
            nutzlast.append('body_text', inhalt.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim());

            if (m?.message_id) {
                nutzlast.append('in_reply_to', m.message_id);
            }

            const bezug = [m?.references, m?.message_id].filter(Boolean).join(' ');

            if (bezug) {
                nutzlast.append('references', bezug);
            }

            anhaenge.forEach((datei, i) => nutzlast.append(`attachments[${i}]`, datei));

            const antwort = await fetch(`${basis}/${konto.id}/send`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
                credentials: 'same-origin',
                body: nutzlast,
            });

            const daten = await antwort.json().catch(() => ({}));

            if (!antwort.ok || !daten.success) {
                melden(typeof daten.message === 'string' ? daten.message : texte.antwortGescheitert, false);

                return;
            }

            melden(texte.antwortGesendet, true);
            setAntwortOffen(false);
        } finally {
            setSendetAntwort(false);
        }
    };


    /* Beim Betreten eines Ordners die neueste bereits GELESENE Nachricht
       oeffnen. Nicht die neueste ueberhaupt: Oeffnen zaehlt als Lesen, und
       in einem Gruppenpostfach saehe die Kollegin sie danach nicht mehr als
       neu. Entscheidung und Begruendung liegen im Paket. */
    useAutoOpen<RowMessage>({
        rows: zeilen,
        key: `${konto?.id ?? ''}:${ordnerPfad}`,
        openedUid: (offene.message as { uid?: number } | null)?.uid ?? null,
        enabled: !liste.loading && !suche.isSearchMode,
        onOpen: (m) => void offene.open(m, ordnerPfad),
    });

    /* j/k blaettern, Escape schliesst. Welche Tasten es gibt, entscheidet das
       Paket; welche hier wirken, entscheidet die Liste der Rueckrufe. */
    /*
     * Die vier Aktionen an der GEOEFFNETEN Nachricht (#9116).
     *
     * Sie standen bis zum 29.10.2026 in der Flaeche, direkt im JSX von
     * `MailMessageView`. Dort waren sie fuer die Tastatur unerreichbar: Der
     * Zuhoerer sitzt hier, und die Flaeche liegt hinter diesem Haken.
     *
     * Folge war, dass die Flaeche vier Tasten nicht anbieten konnte, die der
     * Projekt-Manager seit je hat — und beim Zurueckfuehren seiner Seite
     * haetten sie lautlos aufgehoert zu wirken. Hierher verschoben, Adressen
     * und Nutzlast unveraendert; die Flaeche ruft jetzt dieselben Funktionen
     * statt sie nachzubauen.
     */
    const gelesenUmschalten = () =>
        amGeoeffneten(() =>
            postJson(
                `${basis}/${konto?.id}/message/${offeneKennung()}/seen`,
                { folder: ordnerPfad, seen: !(offene.message as { is_read?: boolean } | null)?.is_read },
                { csrfToken: csrf },
            ),
        );

    const markierungUmschalten = () =>
        amGeoeffneten(() =>
            postJson(
                `${basis}/${konto?.id}/message/${offeneKennung()}/flagged`,
                { folder: ordnerPfad, flagged: !(offene.message as { is_flagged?: boolean } | null)?.is_flagged },
                { csrfToken: csrf },
            ),
        );

    const offeneArchivieren = () =>
        amGeoeffneten(
            () =>
                postJson(
                    `${basis}/${konto?.id}/bulk`,
                    {
                        folder: ordnerPfad,
                        action: 'archive',
                        // Eine ganze Kette, wenn eine offen ist — sonst die eine Nachricht.
                        uids: archivKennungen.length > 0 ? archivKennungen : [offeneKennung()],
                    },
                    { csrfToken: csrf },
                ),
            true,
        );

    const offeneLoeschen = () =>
        amGeoeffneten(
            () => postJson(`${basis}/${konto?.id}/message/${offeneKennung()}/delete`, { folder: ordnerPfad }, { csrfToken: csrf }),
            true,
        );

    useMailboxKeyboard<RowMessage>({
        rows: zeilen,
        openedUid: (offene.message as { uid?: number } | null)?.uid ?? null,
        hasMessage: Boolean(offene.message),
        /*
         * Waehrend des Verfassens gilt nur Escape. Sonst loeschte ein `e` im
         * Text die Mail, an die gerade geantwortet wird.
         */
        enabled: !antwortOffen && !weiterleitenOffen && Boolean(konto),
        onOpen: (m) => void offene.open(m, ordnerPfad),
        onReply: () => {
            setAntwortAnAlle(false);
            setAntwortOffen(true);
        },
        onReplyAll: () => {
            setAntwortAnAlle(true);
            setAntwortOffen(true);
        },
        onForward: () => setWeiterleitenOffen(true),
        onToggleSeen: () => void gelesenUmschalten(),
        onToggleFlag: () => void markierungUmschalten(),
        onArchive: () => void offeneArchivieren(),
        onDelete: () => void offeneLoeschen(),
        onSearch: onSuchen,
        onHelp: onHilfe,
        /*
         * Escape raeumt von oben nach unten ab — in der Reihenfolge, in der
         * die Felder aufgegangen sind. Ohne das schliesst ein Escape im
         * Antwortfeld die ganze Nachricht, und der Text ist weg.
         */
        onEscape: () => {
            if (weiterleitenOffen) {
                setWeiterleitenOffen(false);

                return;
            }

            if (antwortOffen) {
                setAntwortOffen(false);

                return;
            }

            offene.close();
        },
    });

    // Beim ersten Postfach: Ordner holen, dann den, der geoeffnet werden soll.
    useEffect(() => {
        if (!konto) {
            return;
        }

        /* eslint-disable-next-line react-hooks/set-state-in-effect -- Abgeleiteter Zustand, der beim Wechsel zurueckgesetzt werden MUSS — sonst zeigt die Ansicht fuer einen Augenblick die Daten des vorigen Postfachs. */
        setFehler(null);
        offene.close();
        // Die Ordner des vorigen Postfachs gehoeren nicht unter den Namen des neuen.
        ordner.clear();

        void (async () => {
            const zuOeffnen = await ordner.load(konto.id);

            if (zuOeffnen) {
                setOrdnerPfad(zuOeffnen);
                await liste.load({ accountId: konto.id, folder: zuOeffnen, page: 1 });
            }
        })();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [konto?.id]);

    /*
     * Der Haken meldet WAS schiefging, die Worte kommen von hier.
     *
     * Und die Meldung geht wieder WEG, sobald etwas klappt. Eine stehengebliebene
     * Fehlerzeile ueber einer Liste voller Nachrichten behauptet etwas, das
     * sichtbar nicht stimmt — und ist damit schlimmer als keine.
     */
    useEffect(() => {
        const problem = ordner.failure ?? liste.failure ?? offene.failure;

        if (!problem) {
            /* eslint-disable-next-line react-hooks/set-state-in-effect -- Abgeleiteter Zustand, der beim Wechsel zurueckgesetzt werden MUSS — sonst zeigt die Ansicht fuer einen Augenblick die Daten des vorigen Postfachs. */
            setFehler(null);

            return;
        }

        setFehler(
            problem.kind === 'network' ? 'Das Postfach ist gerade nicht erreichbar.' : (problem.message ?? 'Das Postfach hat die Anfrage abgelehnt.'),
        );
    }, [ordner.failure, liste.failure, offene.failure]);


    return {
        /*
         * Der Routen-Vorsatz reist mit: Die Flaeche baut daraus ihre eigenen
         * Adressen, und ein Produkt soll ihn nicht zweimal angeben muessen.
         */
        basis,
        amGeoeffneten,
        gelesenUmschalten,
        markierungUmschalten,
        offeneArchivieren,
        offeneLoeschen,
        antwortAnAlle,
        antwortOffen,
        antwortSenden,
        archivKennungen,
        assistent,
        auswahl,
        beschaeftigt,
        chatQuelle,
        csrf,
        fehler,
        filterOffen,
        konto,
        liste,
        /*
         * Wieder heraus, obwohl es hereinkam: Die Flaeche braucht es fuer das
         * Weiterleiten-Feld. Gaebe das Produkt es ihr ein zweites Mal, koennten
         * die beiden auseinanderlaufen — und dann meldet die Antwort anders als
         * die Weiterleitung.
         */
        melden,
        nachrichtBeschaeftigt,
        offene,
        offeneKennung,
        ordner,
        ordnerPfad,
        ordnerSichtbar,
        ordnerVerwaltung,
        sammelAktion,
        schlummern,
        sendetAntwort,
        setAntwortAnAlle,
        setAntwortOffen,
        setFehler,
        setFilterOffen,
        setKonto,
        setOrdnerPfad,
        setOrdnerSichtbar,
        setWeiterleitenOffen,
        sichtbareZeilen,
        suche,
        verlauf,
        weiterleitenOffen,
        werkzeuge,
        zeilen,
        zuweisungen,
    };
}

/**
 * Was der Haken herausgibt — abgeleitet, nicht zweitgeschrieben.
 *
 * Eine Hand gepflegte Schnittstelle daneben waere eine zweite Wahrheit: Sie
 * stimmt beim Anlegen und laeuft beim ersten neuen Wert auseinander. `ReturnType`
 * kann nicht veralten.
 *
 * Gebraucht wird der Name von {@link MailboxFlaeche}, die den ganzen Satz als
 * EINEN Wert annimmt statt als neununddreissig Eigenschaften.
 */
export type MailboxSeiteErgebnis = ReturnType<typeof useMailboxSeite>;
