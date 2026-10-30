import { type ComponentType, type ReactNode } from 'react';
import { FolderInput, FolderOpen, UserCheck } from 'lucide-react';
import { looksLikeFolder } from './folders';
import { germanLabels } from './germanLabels';
import type { InlineAntwortProps } from './InlineAntwort';
import type { WeiterleitenFeldProps } from './inertia/WeiterleitenFeld';
import { MailAssignmentPicker } from './MailAssignmentPicker';
import { MailboxBrowser } from './MailboxBrowser';
import { MailboxChat, type MailboxChatProps } from './MailboxChat';
import { MailboxHeader, type MailboxHeaderAccessory } from './MailboxHeader';
import { MailboxSidePanel, MailboxSidePanelHandle } from './MailboxSidePanel';
import { Button } from './ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from './ui/dropdown-menu';
import { MailConversationPane } from './MailConversationPane';
import { MailFolderList } from './MailFolderList';
import { MailFolderManager } from './MailFolderManager';
import { MailListToolbar } from './MailListToolbar';
import { MailMessageList, type MailMessageListProps } from './MailMessageList';
import { MailMessageView } from './MailMessageView';
import { MailWerkzeugleiste } from './MailWerkzeugleiste';
import { postJson } from './transport';
import { Skeleton } from './ui/skeleton';
import type { MailFolder } from './MailFolderList';
import type { RowMessage } from './rows';
import type { MailboxSeiteErgebnis, MailboxSeiteKonto } from './useMailboxSeite';
import type { MailWerkzeug } from './useMailWerkzeuge';

/**
 * Die Fläche der Mailseite — für alle Produkte dieselbe (#8377).
 *
 * ## Gemessen, nicht geschätzt
 *
 * Am 22.10.2026 die JSX-Flächen der drei Mailseiten auf Token-Ebene
 * verglichen (ohne Kommentare und Umbrüche, denn die Zeile ist eine
 * Formatierungsentscheidung):
 *
 *     CRM           364 Zeilen, 2825 Token
 *     Verwaltung    374 Zeilen, 2914 Token    100,0 % (2824 von 2825)
 *     Brain         761 Zeilen, 3434 Token     97,3 % (2749 von 2825)
 *
 * Die Verwaltung war Token für Token dieselbe Fläche — **ein** Token wich ab.
 *
 * Der Manager bleibt draußen: Dort sind es 2 % (gemessen am 20.10.2026). Er
 * führt seinen eigenen Archiv-Weg und seine eigene Suche, und eine Extraktion
 * wäre dort keine Entdopplung, sondern eine Neuschrift.
 *
 * ## Warum das kein Selbstzweck ist
 *
 * Die Folge der Kopien war nicht Mehrarbeit, sondern Stillstand. Vier der acht
 * Fehler des 20.10.2026 lebten in Code, der mehrfach kopiert stand, und
 * mussten einmal gefunden und mehrfach nachgezogen werden. Diese Fläche senkt
 * den Preis des NÄCHSTEN Fehlers.
 *
 * ## Die vier Einschnitte, und woher sie kommen
 *
 * Nicht erfunden, sondern aus dem Vergleich abgelesen — genau an diesen vier
 * Stellen gingen die drei Flächen auseinander:
 *
 * | Einschnitt | CRM | Verwaltung | Brain |
 * |---|---|---|---|
 * | `vorDerAnsicht` | Kontaktleiste | Ticket- und Anhangleiste | — |
 * | `zusatzAktionen` | Schlummer-Menü | Schlummer-Menü | dazu „Aus der Ablage entfernen" |
 * | `nachDerSeite` | — | — | der Entfernen-Dialog |
 * | `werkzeugeVerbergen` | eigene Verben aus | eigene Verben aus | nichts aus |
 * | `chatZusatz` | — | — | — |
 *
 * `chatZusatz` steht noch leer, weil es fuer den Projekt-Manager ergaenzt
 * wurde (#9116): Dort haengt eine Spracheingabe am Assistenten, und ohne
 * diesen Durchgang haette der Umstieg auf diese Flaeche sie gekostet.
 *
 * ## Warum das Schlummer-Menü ein Einschnitt bleibt
 *
 * Es braucht ein Ausklapp-Menü, und das kommt in allen drei Produkten aus
 * deren eigenem UI-Kit. Es hierher zu holen hieße, eine weitere Abhängigkeit
 * aufzunehmen, um rund zwanzig Zeilen je Produkt zu sparen — und die drei
 * Zeitpunkte („heute Abend", „morgen früh", „nächste Woche") sind ohnehin eine
 * Entscheidung und keine Darstellung.
 */
export interface MailboxFlaecheProps {
    /**
     * Der Rückgabewert von {@link useMailboxSeite} — alle Werte in einem.
     *
     * Als EIN Wert und nicht als neununddreißig Eigenschaften: Die Fläche und
     * der Haken gehören zusammen, und eine Liste von Durchreich-Eigenschaften
     * wäre eine Stelle, die bei jeder Erweiterung des Hakens nachgezogen
     * werden müsste.
     */
    seite: MailboxSeiteErgebnis;
    konten: MailboxSeiteKonto[];
    titel?: string;
    /** Steht der Postfach-Assistent zur Verfügung? Entscheidet das Produkt. */
    chatVerfuegbar?: boolean;
    /** Inertia-Anwendungen reichen hier ihren Router herein. */
    navigieren: (href: string) => void;
    leerText?: string;
    /** Die Leisten des Produkts über der geöffneten Mail. */
    vorDerAnsicht?: ReactNode;
    /** Was neben die Knöpfe der Leseansicht gehört — etwa das Schlummer-Menü. */
    zusatzAktionen?: ReactNode;
    /** Dialoge und Ähnliches, die nach der Seite gezeichnet werden. */
    nachDerSeite?: ReactNode;
    /**
     * Das Antwortfeld — als KOMPONENTE, nicht als fertiger Knoten.
     *
     * Der Unterschied ist der Punkt: Alle Eigenschaften, die es braucht
     * (Konto, Nachricht, „allen antworten", Senden, Abbrechen), kommen aus dem
     * Haken — die Fläche hat sie also schon. Ein Produkt, das den Knoten
     * selbst baut, müsste fünfzehn Werte durchreichen, die hier daneben
     * liegen; und das stand bis zum 22.10.2026 dreimal im Bestand.
     *
     * Das Paket bringt eines mit ({@link InlineAntwort}). Wer ein anderes
     * will, gibt es hier.
     */
    antwortFeld?: ComponentType<InlineAntwortProps>;
    /**
     * Dasselbe für das Weiterleiten.
     *
     * Liegt im Inertia-Einstieg des Pakets ({@link WeiterleitenFeld}) und wird
     * deshalb hereingegeben statt hier importiert: Diese Fläche kennt kein
     * Inertia, sie nimmt ihren Router als Eigenschaft.
     */
    weiterleitenFeld?: ComponentType<WeiterleitenFeldProps>;
    /**
     * Zusatzknoepfe im Eingabefeld des Assistenten — etwa ein Mikrofon.
     *
     * `MailboxChat` nimmt `controls` seit jeher; die Flaeche hat es nur nicht
     * weitergegeben. Folge: Der Projekt-Manager haette beim Umstieg auf diese
     * Flaeche seine Spracheingabe verloren — und damit einen Grund gehabt, die
     * Seite doch selbst zu bauen (#9116).
     *
     * Die drei anderen Produkte geben nichts an und sehen keinen Unterschied.
     */
    chatZusatz?: MailboxChatProps['controls'];
    /**
     * Rechtsklick auf eine Zeile der Liste.
     *
     * `MailMessageList` nimmt `onRowContextMenu` seit jeher; diese Flaeche hat
     * es nicht weitergegeben. Der Projekt-Manager haengt dort seine
     * Wiedervorlage an, und beim Umstieg waere sie verschwunden — ohne Ersatz,
     * denn seit #7690 ist das Menue der EINZIGE Weg dorthin (das Zuweisen ist
     * an das Abzeichen in der Zeile gewandert, #9116).
     *
     * Das Menue selbst zeichnet das Produkt, ueber `nachDerSeite`. Hierher
     * kommt nur, WANN es aufgeht.
     */
    /**
     * Was neben einem Postfach-Namen im Waehler steht — ein Schloss, ein
     * Globus, nichts. Welche Postfaecher geteilt sind, weiss nur das Produkt:
     * `MailboxSeiteKonto` kennt id, email und name, sonst nichts. Ohne diesen
     * Durchgang verlaere der Manager beim Umstieg auf die Flaeche den Globus,
     * der ein Gruppenpostfach von einem eigenen unterscheidet (#9116).
     *
     * `MailboxHeader` kann das seit jeher (`accountAccessory`) — die Flaeche
     * hat es nur nicht weitergereicht.
     */
    kontoZusatz?: MailboxHeaderAccessory;
    /**
     * Eigene Knoepfe am rechten Rand der Listenleiste — ein Filter, eine
     * Hilfe, was das Produkt dort braucht. Der Manager haengt dort „nur mir
     * zugewiesene" und die Tastenkuerzel hin (#9116).
     */
    /**
     * Ob dieses Produkt Ketten laden KANN (#1171).
     *
     * Vorgabe `false`, und das ist Absicht: Bis zum 30.10.2026 zeigte die
     * Leiste den Schalter „nach Konversationen gruppieren" ueberall. Nur holt
     * die Vorgabe-Adresse in jedem Fall die flache Liste, und CRM, Verwaltung
     * und AI Brain haben gar keinen Endpunkt fuer Ketten.
     *
     * Gemessen an jenem Tag auf AI Brain, frisch geladen:
     *
     *     ungruppiert   25 Zeilen
     *     gruppiert      0 Zeilen, dazu „Keine E-Mails"
     *     wieder aus    25 Zeilen
     *
     * Das sah nicht nach einem wirkungslosen Schalter aus, sondern nach einem
     * leeren Postfach. Ein Schalter, der nichts kann, gehoert deshalb weg und
     * nicht ausgegraut: Ausgegraut sagt „gerade nicht", und das waere eine
     * zweite Unwahrheit.
     *
     * Ein Produkt mit Ketten-Endpunkt setzt `true` und bekommt den Schalter
     * zurueck — der Manager, sobald er seine Adressen ueber `routen` setzt.
     */
    kettenMoeglich?: boolean;
    leisteZusatz?: ReactNode;
    /**
     * Eine Zeile UNTER der Listenleiste, fuer eine Aussage ueber die Liste
     * selbst — nicht fuer eine Meldung. Der Manager sagt dort, wie alt sein
     * Suchverzeichnis ist: Wer aus einem Verzeichnis sucht, haelt eine
     * unvollstaendige Liste sonst fuer vollstaendig.
     */
    leisteHinweis?: ReactNode;
    zeilenMenue?: MailMessageListProps<RowMessage>['onRowContextMenu'];
    /**
     * Einen Entwurf weiterbearbeiten.
     *
     * Die Leseansicht zeigt den Knopf nur, wenn die Nachricht in einem
     * Entwurfsordner liegt UND dieser Rueckruf da ist. Erkannt wird der
     * Ordner hier (`looksLikeFolder`), das Bearbeiten selbst gehoert dem
     * Produkt: Der Projekt-Manager fuehrt dafuer auf eine eigene Seite.
     *
     * Ohne diesen Platz zeigte die Flaeche an einem Entwurf gar keinen Knopf
     * — in CRM, Verwaltung und AI Brain ist das heute so, und der Manager
     * haette es beim Umstieg verloren (#9116).
     */
    entwurfBearbeiten?: () => void;
    werkzeugeVerbergen?: (werkzeug: MailWerkzeug) => boolean;
    werkzeugeEigene?: Record<string, () => void>;
}

export function MailboxFlaeche({
    seite,
    konten,
    titel = 'E-Mails',
    chatVerfuegbar = false,
    navigieren,
    leerText = 'Diesem System ist noch kein Postfach zugeteilt.',
    vorDerAnsicht,
    zusatzAktionen,
    nachDerSeite,
    antwortFeld: AntwortFeld,
    weiterleitenFeld: WeiterleitenFeld,
    chatZusatz,
    kettenMoeglich = false,
    kontoZusatz,
    leisteHinweis,
    leisteZusatz,
    zeilenMenue,
    entwurfBearbeiten,
    werkzeugeVerbergen,
    werkzeugeEigene,
}: MailboxFlaecheProps) {
    const {
        /*
         * Der Routen-Vorsatz des Produkts (#9116). Vorgabe `/mailbox`, der
         * Projekt-Manager liegt unter `/emails`. Er kommt aus `seite`, damit
         * ein Produkt ihn nur EINMAL angibt — beim Haken.
         */
        basis,
        amGeoeffneten,
        /*
         * Die vier Aktionen an der geoeffneten Nachricht kommen seit #9116 aus
         * dem Haken. Sie standen hier im JSX und waren damit fuer die
         * Tastatur unerreichbar — der Zuhoerer sitzt im Haken.
         */
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
        melden,
        nachrichtBeschaeftigt,
        offeneVerschieben,
        offene,
        offeneKennung,
        ordner,
        ordnerPfad,
        ordnerSichtbar,
        ordnerVerwaltung,
        sammelAktion,
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
    } = seite;

    const zeigtAssistent = chatVerfuegbar && konto !== null && assistent.sichtbar;

    /*
     * Die Ordner, in die verschoben werden darf — fuer das Sammel-Verschieben
     * in der Leiste UND fuer die eine offene Nachricht. Dieselbe Liste, weil
     * es dieselbe Frage ist (A1, #9116).
     *
     * `targets` kommt aus `{basis}/{konto}/all-folders` und ist vollstaendig;
     * `folders` ist die Seitenleiste und laesst z. B. den Postausgang weg.
     * Bis zum 30.10.2026 nahm die Sammelleiste `folders`, und `targets` wurde
     * NIE geladen — `loadTargetsOnce` rief niemand.
     *
     * Der Rueckfall ist Absicht: Zwischen dem Oeffnen des Menues und der
     * Antwort liegt ein Augenblick, und ein leeres Menue sieht kaputt aus.
     * Solange die vollstaendige Liste fehlt, stehen die bekannten Ordner da.
     */
    const verschiebeZiele = (ordner.targets.length > 0 ? ordner.targets : ordner.folders) as MailFolder[];

    const zieleLaden = () => void ordner.loadTargetsOnce(konto?.id ?? '');

    /*
     * EIN Lesezugriff auf die geoeffnete Nachricht, mit erklaerter Form.
     *
     * Der Haken fuehrt sie absichtlich lose (`unknown`-nah): Was ein Rumpf
     * enthaelt, entscheidet der Server, und die Ansichten unten nehmen
     * verschiedene Teile davon. Die Felder, die Antworten und Weiterleiten
     * brauchen, stehen aber fest — die werden hier einmal benannt, statt an
     * zehn Stellen je einzeln zugesichert.
     */
    const nachricht = offene.message as
        | {
              /* Seit #1177: Die Liste markiert damit die geoeffnete Zeile.
                 Gehoert hierher und nicht an zehn Einzelstellen — dieselbe
                 Begruendung wie fuer die Felder darunter. */
              uid?: number | string;
              subject?: string;
              from_name?: string | null;
              from_address?: string;
              to?: { email: string; name?: string }[];
              date?: string;
              body_html?: string | null;
              body_text?: string | null;
              attachments?: { filename: string }[];
          }
        | null;

    return (
        <>
            {/* Echte Hoehe statt `flex-1`: Der Mail-Browser ist genau so hoch
                wie das Fenster, und seine Spalten scrollen selbst. Mit `flex-1`
                allein fehlt der Bezug — der Kasten waechst mit dem Inhalt, und
                dann scrollt die ganze SEITE statt des Verlaufs.

                5rem, nicht 4rem: Die Kopfzeile ist 4rem hoch, und `main` traegt
                zusaetzlich 8px Rand oben und unten. Wer nur den Header abzieht,
                bekommt einen Kasten, der exakt 16px zu hoch ist — und damit
                einen ZWEITEN Bildlauf neben dem des Verlaufs. Gemessen, nicht
                geschaetzt: `main` 2407px bei 2407px Fenster, Wrapper 2423px.

                `dvh` statt `vh`, seit #1079: Auf dem iPhone zaehlt `vh` die
                Adressleiste MIT, auch wenn sie gerade Platz wegnimmt. Der
                Kasten ist dann hoeher als das, was man sieht — und was unten
                heraushaengt, ist nicht wegzuscrollen, weil der Kasten selbst
                ja nicht scrollt. Genau die „Bedienelemente teilweise nicht
                anklickbar" aus der Meldung. Am Schreibtisch sind die beiden
                Einheiten gleich, dort aendert sich nichts. */}
            <div className="flex h-[calc(100dvh-5rem)] flex-col overflow-hidden">
                <MailboxHeader
                    title={titel}
                    labels={germanLabels.header}
                    accounts={konten.map((k) => ({ id: k.id, name: k.name }))}
                    accountId={konto?.id ?? null}
                    onAccountChange={(wert) => setKonto(konten.find((k) => k.id === Number(wert)) ?? null)}
                    accountAccessory={kontoZusatz}
                    onAddressBook={() => navigieren(`${basis}/address-book`)}
                    onCompose={() => navigieren(`${basis}/compose`)}
                    onRefresh={
                        konto ? () => void liste.load({ accountId: konto.id, folder: ordnerPfad, page: liste.page, refresh: true }) : undefined
                    }
                    refreshing={liste.refreshing}
                    error={fehler}
                />

                {konten.length === 0 ? (
                    <div className="flex flex-1 items-center justify-center text-muted-foreground">
                        <p>{leerText}</p>
                    </div>
                ) : (
                    <MailboxBrowser
                        folders={
                            <MailFolderList
                                folders={ordner.folders}
                                labels={{ heading: 'Ordner', createFolder: 'Neuer Ordner' }}
                                selectedPath={ordnerPfad}
                                loading={ordner.loading}
                                onCreate={() => ordnerVerwaltung.setDialog({ open: true, mode: 'create', name: '', path: '' })}
                                onFolderContextMenu={(e, f) => ordnerVerwaltung.setMenu({ open: true, x: e.clientX, y: e.clientY, folder: f })}
                                onSelect={(pfad) => {
                                    setOrdnerPfad(pfad);
                                    offene.close();
                                    void liste.load({ accountId: konto?.id ?? '', folder: pfad, page: 1 });
                                }}
                            />
                        }
                        showFolders={ordnerSichtbar}
                        list={
                            <>
                                <MailListToolbar
                                    extras={leisteZusatz}
                                    notice={leisteHinweis}
                                    labels={germanLabels.toolbar}
                                    hint={zuweisungen.erreichbar ? null : 'Zuständigkeiten nicht erreichbar'}
                                    showFolders={ordnerSichtbar}
                                    onToggleFolders={() => setOrdnerSichtbar(!ordnerSichtbar)}
                                    total={liste.total}
                                    page={liste.page}
                                    totalPages={liste.totalPages}
                                    loading={liste.loading}
                                    onPage={(seite_) => void liste.load({ accountId: konto?.id ?? '', folder: ordnerPfad, page: seite_ })}
                                    grouped={liste.grouped}
                                    onToggleGrouped={
                                        kettenMoeglich
                                            ? () => void liste.setGrouped(!liste.grouped, konto?.id ?? '', ordnerPfad)
                                            : undefined
                                    }
                                    filtersOpen={filterOffen}
                                    onToggleFilters={() => setFilterOffen((v) => !v)}
                                    selected={auswahl.ticked}
                                    affected={auswahl.targets.length}
                                    busy={beschaeftigt}
                                    onMarkRead={() => void sammelAktion('read')}
                                    onMarkUnread={() => void sammelAktion('unread')}
                                    onArchive={() => void sammelAktion('archive')}
                                    onDelete={() => void sammelAktion('delete')}
                                    onClearSelection={auswahl.clear}
                                    moveTargets={verschiebeZiele}
                                    onMoveMenuOpen={zieleLaden}
                                    currentFolder={ordnerPfad}
                                    onMove={(ziel) => void sammelAktion('move', ziel)}
                                    search={{
                                        query: suche.criteria.query,
                                        from: suche.criteria.from,
                                        subject: suche.criteria.subject,
                                        since: suche.criteria.since,
                                        unseen: suche.criteria.unseen,
                                        allFolders: suche.criteria.allFolders,
                                        canSearch: suche.canSearch,
                                        searching: suche.searching,
                                        isSearchMode: suche.isSearchMode,
                                        foldersSearched: suche.foldersSearched,
                                        totalFound: suche.totalFound,
                                        set: (feld, wert) => suche.set(feld, wert as never),
                                        run: () => void suche.search(),
                                        clear: () => void suche.clear(),
                                    }}
                                />

                                {/* Der eigene Scroll-Container: Die Spalte schneidet nur ab,
                                    damit ein Produkt mit Werkzeugleiste und Blaetterung selbst
                                    entscheidet, WAS darin scrollt. Hier scrollen nur die
                                    Nachrichten — die Leiste darueber bleibt. */}
                                <div className="min-h-0 flex-1 overflow-y-auto">
                                    <MailMessageList<RowMessage>
                                        rows={sichtbareZeilen(zeilen)}
                                        isSearchMode={suche.isSearchMode}
                                        labels={germanLabels.list}
                                    onRowContextMenu={zeilenMenue}
                                        loading={liste.loading}
                                        selectedUids={auswahl.ticked}
                                        formatDate={(iso) => (iso ? new Date(iso).toLocaleString('de-DE') : '-')}
                                        formatSender={(m) => m.from_name || m.from_address || 'Unbekannt'}
                                        onOpen={(msg) => void offene.open(msg, ordnerPfad)}
                                        onToggleSelect={auswahl.toggle}
                                        /*
                                         * #1177. Alle drei fuehrt `useMailboxSeite`
                                         * laengst — die Flaeche hat sie nur nicht
                                         * weitergereicht, und die Liste faellt ohne sie
                                         * still auf Vorgaben zurueck:
                                         *
                                         *   openedUid       null, also stimmt der
                                         *                   Vergleich nie und die offene
                                         *                   Zeile bleibt unmarkiert
                                         *   expandedThreads bei jedem Zeichnen ein neues
                                         *                   leeres Set
                                         *   onToggleThread  undefined — der Pfeil wird
                                         *                   gezeichnet und ruft ins Leere
                                         *
                                         * Gemessen am 30.10.2026 auf AI Brain: Zeile
                                         * angeklickt, Nachricht offen, 0 von 25 Zeilen
                                         * hervorgehoben.
                                         */
                                        openedUid={nachricht?.uid ?? null}
                                        expandedThreads={auswahl.expanded}
                                        onToggleThread={auswahl.toggleThread}
                                        loadingPlaceholder={
                                            <div className="space-y-2 p-2">
                                                {[0, 1, 2, 3, 4].map((i) => (
                                                    <Skeleton key={i} className="h-12 w-full" />
                                                ))}
                                            </div>
                                        }
                                        rowAccessory={(z) => (
                                            <MailAssignmentPicker
                                                labels={germanLabels.zuweisung}
                                                users={zuweisungen.users}
                                                assignment={zuweisungen.fuer(z.msg)}
                                                busy={zuweisungen.busy}
                                                onAssign={(id) => void zuweisungen.zuweisen(z.msg, id)}
                                                onUnassign={() => void zuweisungen.aufheben(z.msg)}
                                            />
                                        )}
                                    />
                                </div>
                            </>
                        }
                        view={
                            <>
                                {vorDerAnsicht}

                                <MailWerkzeugleiste
                                    werkzeuge={werkzeuge.werkzeuge}
                                    unerreichbar={werkzeuge.unerreichbar}
                                    gescheitert={werkzeuge.gescheitert}
                                    laedt={werkzeuge.laedt}
                                    leerAberVollstaendig={werkzeuge.leerAberVollstaendig}
                                    erreicht={werkzeuge.erreicht}
                                    ausloesen={werkzeuge.ausloesen}
                                    verbergen={werkzeugeVerbergen}
                                    eigene={werkzeugeEigene}
                                    labels={germanLabels.werkzeuge}
                                    onMeldung={(meldung, gelungen) => {
                                        // Beides zeigen, auch die Absage: Ein Verb, das nicht
                                        // konnte, ist eine Antwort — sie zu verschlucken heisst
                                        // „geklickt, nichts passiert".
                                        if (!gelungen) {
                                            setFehler(meldung);
                                        }
                                    }}
                                    onNavigate={navigieren}
                                />

                                <MailConversationPane
                                    state={verlauf}
                                    labels={germanLabels.conversation}
                                    ownAddresses={konto ? [konto.email] : []}
                                    formatDateTime={(iso) => new Date(iso).toLocaleString('de-DE')}
                                    onOpenOriginal={(e) => {
                                        // Zurueck in die gewohnte Ansicht, bei genau dieser
                                        // Nachricht. Mehr als die Kennung braucht der Oeffner
                                        // nicht — den Rest holt er aus dem Postfach.
                                        if (e.uid !== null && e.folder) {
                                            void offene.open({ uid: e.uid } as never, e.folder);
                                        }
                                    }}
                                >
                                    {weiterleitenOffen && konto && nachricht && WeiterleitenFeld ? (
                                        /*
                                         * Die Eigenschaften baut die Flaeche, nicht das Produkt
                                         * — sie liegen alle im Haken. Weiterleiten holt die
                                         * Anhaenge der Ursprungsnachricht serverseitig; es
                                         * reicht der Zeiger darauf (Ordner und Kennung), keine
                                         * Nutzlast. Eine Weiterleitung mit 20 MB Anhang reist
                                         * damit nicht zweimal durch die Leitung.
                                         */
                                        <WeiterleitenFeld
                                            account={{ id: konto.id, name: konto.name, email: konto.email }}
                                            source={{ type: 'imap', folder: ordnerPfad, uid: offeneKennung() }}
                                            subject={nachricht.subject ?? ''}
                                            fromName={nachricht.from_name ?? null}
                                            fromAddress={nachricht.from_address ?? ''}
                                            toAddresses={nachricht.to ?? []}
                                            date={nachricht.date ?? ''}
                                            bodyHtml={nachricht.body_html ?? null}
                                            bodyText={nachricht.body_text ?? null}
                                            originalAttachments={nachricht.attachments ?? []}
                                            endpoints={{ forward: `${basis}/${konto.id}/forward` }}
                                            melden={(text) => melden(text, true)}
                                            onSent={() => setWeiterleitenOffen(false)}
                                            onCancel={() => setWeiterleitenOffen(false)}
                                        />
                                    ) : antwortOffen && konto && nachricht && AntwortFeld ? (
                                        <AntwortFeld
                                            account={{ id: konto.id, name: konto.name, email: konto.email }}
                                            message={offene.message as never}
                                            replyAll={antwortAnAlle}
                                            sending={sendetAntwort}
                                            onSend={(rumpf, an, kopie, betreff, anhaenge, zitatHtml) =>
                                                void antwortSenden(rumpf, an, kopie, betreff, anhaenge, zitatHtml)
                                            }
                                            onCancel={() => setAntwortOffen(false)}
                                        />
                                    ) : (
                                        <MailMessageView
                                            message={offene.message as never}
                                            labels={germanLabels.view}
                                            loading={offene.loading}
                                            /*
                                             * Es zaehlt der Ordner, in dem die Nachricht WIRKLICH
                                             * liegt — nicht der links ausgewaehlte. Ein Entwurf, den
                                             * man ueber die Suche findet, bleibt einer, auch wenn
                                             * daneben der Posteingang steht.
                                             *
                                             * Die Erkennung liegt im Paket: Sie fragt zuerst die
                                             * IMAP-Flags und kennt die kodierten Schreibweisen
                                             * („Entw&APw-rfe" bei Office 365), an denen ein
                                             * handgeschriebenes Muster vorbeigeht.
                                             */
                                            isDraft={(() => {
                                                const pfad = offene.folder ?? ordnerPfad;
                                                const ordnerZeile = (ordner.folders as MailFolder[]).find((f) => f.path === pfad);

                                                return looksLikeFolder({ path: pfad, name: ordnerZeile?.name, flags: ordnerZeile?.flags }, 'Drafts');
                                            })()}
                                            onEditDraft={entwurfBearbeiten}
                                            busy={{ archiving: nachrichtBeschaeftigt, deleting: nachrichtBeschaeftigt }}
                                            onReply={(alle) => {
                                                setAntwortAnAlle(alle);
                                                setAntwortOffen(true);
                                            }}
                                            onForward={() => setWeiterleitenOffen(true)}
                                            extraActions={zusatzAktionen}
                                            /*
                                             * A1 (#9116): Die Flaeche zeichnet ihn selbst.
                                             * Vorher baute ihn jedes Produkt — im Manager
                                             * 56 Zeilen, in den anderen dreien gar keiner,
                                             * obwohl alle vier die Adresse haben.
                                             */
                                            moveControl={
                                                <DropdownMenu onOpenChange={(offen) => offen && zieleLaden()}>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={nachrichtBeschaeftigt}
                                                            title={germanLabels.toolbar.moveTitle}
                                                            aria-label={germanLabels.toolbar.moveTitle}
                                                        >
                                                            <FolderInput className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end" className="max-h-80 overflow-auto">
                                                        {verschiebeZiele.map((ziel) => (
                                                            <DropdownMenuItem
                                                                key={ziel.path}
                                                                /*
                                                                 * Am Ordner der NACHRICHT messen, nicht am
                                                                 * links gewaehlten: Bei einem Suchtreffer
                                                                 * aus einem anderen Ordner sperrte der
                                                                 * Manager bis #7690 den falschen Eintrag
                                                                 * und liess den wirkungslosen zu.
                                                                 */
                                                                disabled={ziel.path === (offene.folder ?? ordnerPfad)}
                                                                onSelect={() => void offeneVerschieben(ziel.path)}
                                                            >
                                                                <FolderOpen className="mr-2 h-4 w-4" />
                                                                {ziel.name}
                                                            </DropdownMenuItem>
                                                        ))}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            }
                                            /*
                                             * A2 (#9116): Wer fuer diese Mail zustaendig
                                             * ist, steht neben dem Betreff.
                                             *
                                             * Die Daten fuehrt der Haken laengst — nur die
                                             * Anzeige fehlte. Der Manager hatte sie, die
                                             * anderen drei nicht, obwohl alle vier
                                             * Zustaendigkeiten fuehren. Geaendert wird sie
                                             * weiterhin am Abzeichen in der Zeile; hier
                                             * steht sie nur.
                                             *
                                             * `name` traegt auch dann, wenn `user_id` null
                                             * ist: Die Zustaendigkeit kann fuer jemanden
                                             * stehen, den DIESES System nicht kennt. Sie
                                             * dann wegzulassen hiesse, die Mail saehe frei
                                             * aus, obwohl jemand an ihr arbeitet.
                                             */
                                            headerAccessory={(() => {
                                                const zustaendig = nachricht ? zuweisungen.fuer(nachricht as never) : null;

                                                return zustaendig ? (
                                                    <span
                                                        className="inline-flex items-center gap-1 rounded-full bg-primary/15 px-2 py-0.5 text-xs font-medium text-primary"
                                                        title={germanLabels.zuweisung.assignedTo(zustaendig.name)}
                                                        data-slot="mailbox-zustaendig"
                                                    >
                                                        <UserCheck className="h-3.5 w-3.5 shrink-0" />
                                                        {germanLabels.zuweisung.assignedTo(zustaendig.name)}
                                                    </span>
                                                ) : null;
                                            })()}
                                            onToggleRead={() => void gelesenUmschalten()}
                                            onToggleFlag={() => void markierungUmschalten()}
                                            archiveCount={archivKennungen.length}
                                            onArchive={() => void offeneArchivieren()}
                                            onDelete={() => void offeneLoeschen()}
                                            formatDateTime={(iso) => new Date(iso).toLocaleString('de-DE')}
                                            formatAddressList={(a) => a.map((x) => x.name || x.email).join(', ')}
                                            formatFileSize={(b) => `${Math.round(b / 1024)} KB`}
                                            attachmentUrl={(anhang) =>
                                                konto
                                                    ? `${basis}/${konto.id}/message/${(offene.message as { uid?: number | string } | null)?.uid ?? ''}/attachment/${anhang.index}/download?folder=${encodeURIComponent(ordnerPfad)}`
                                                    : '#'
                                            }
                                        />
                                    )}
                                </MailConversationPane>
                            </>
                        }
                        asideGewaehlt={assistent.gewaehlt}
                        aside={
                            zeigtAssistent && konto ? (
                                <MailboxSidePanel title="Postfach-Assistent" onClose={assistent.verbergen}>
                                    <MailboxChat account={konto} source={chatQuelle} controls={chatZusatz} />
                                </MailboxSidePanel>
                            ) : undefined
                        }
                    />
                )}

                <MailFolderManager<MailFolder>
                    labels={germanLabels.folders}
                    menu={ordnerVerwaltung.menu}
                    onMenuChange={ordnerVerwaltung.setMenu}
                    dialog={ordnerVerwaltung.dialog}
                    onDialogChange={ordnerVerwaltung.setDialog}
                    onSubmit={() => void ordnerVerwaltung.submit()}
                    deleteTarget={ordnerVerwaltung.deleteTarget}
                    onDeleteTargetChange={ordnerVerwaltung.setDeleteTarget}
                    onConfirmDelete={() => void ordnerVerwaltung.confirmDelete()}
                    busy={ordnerVerwaltung.busy}
                />

                {chatVerfuegbar && konto && !assistent.sichtbar && (
                    <MailboxSidePanelHandle label="AI-Brain-Chat" onClick={assistent.zeigen} />
                )}

                {/* Auf schmalen Schirmen ist die Spalte weg, solange sie nur per
                    Vorgabe offen steht (siehe `asideGewaehlt`). Ohne diesen Griff
                    waere der Assistent dort gar nicht mehr erreichbar — und ein
                    Antippen macht aus der Vorgabe eine Wahl, ab da deckt er wie
                    gehabt ab. */}
                {chatVerfuegbar && konto && assistent.sichtbar && !assistent.gewaehlt && (
                    <div className="md:hidden">
                        <MailboxSidePanelHandle label="AI-Brain-Chat" onClick={assistent.zeigen} />
                    </div>
                )}
            </div>

            {nachDerSeite}
        </>
    );
}
