import { type ComponentType, type ReactNode } from 'react';
import { germanLabels } from './germanLabels';
import type { InlineAntwortProps } from './InlineAntwort';
import type { WeiterleitenFeldProps } from './inertia/WeiterleitenFeld';
import { MailAssignmentPicker } from './MailAssignmentPicker';
import { MailboxBrowser } from './MailboxBrowser';
import { MailboxChat } from './MailboxChat';
import { MailboxHeader } from './MailboxHeader';
import { MailboxSidePanel, MailboxSidePanelHandle } from './MailboxSidePanel';
import { MailConversationPane } from './MailConversationPane';
import { MailFolderList } from './MailFolderList';
import { MailFolderManager } from './MailFolderManager';
import { MailListToolbar } from './MailListToolbar';
import { MailMessageList } from './MailMessageList';
import { MailMessageView } from './MailMessageView';
import { MailWerkzeugleiste } from './MailWerkzeugleiste';
import { postJson } from './transport';
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
                                    onToggleGrouped={() => void liste.setGrouped(!liste.grouped, konto?.id ?? '', ordnerPfad)}
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
                                    moveTargets={ordner.folders as MailFolder[]}
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
                                        loading={liste.loading}
                                        selectedUids={auswahl.ticked}
                                        formatDate={(iso) => (iso ? new Date(iso).toLocaleString('de-DE') : '-')}
                                        formatSender={(m) => m.from_name || m.from_address || 'Unbekannt'}
                                        onOpen={(msg) => void offene.open(msg, ordnerPfad)}
                                        onToggleSelect={auswahl.toggle}
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
                                            busy={{ archiving: nachrichtBeschaeftigt, deleting: nachrichtBeschaeftigt }}
                                            onReply={(alle) => {
                                                setAntwortAnAlle(alle);
                                                setAntwortOffen(true);
                                            }}
                                            onForward={() => setWeiterleitenOffen(true)}
                                            extraActions={zusatzAktionen}
                                            onToggleRead={() =>
                                                void amGeoeffneten(() =>
                                                    postJson(
                                                        `${basis}/${konto?.id}/message/${offeneKennung()}/seen`,
                                                        { folder: ordnerPfad, seen: !(offene.message as { is_read?: boolean } | null)?.is_read },
                                                        { csrfToken: csrf },
                                                    ),
                                                )
                                            }
                                            onToggleFlag={() =>
                                                void amGeoeffneten(() =>
                                                    postJson(
                                                        `${basis}/${konto?.id}/message/${offeneKennung()}/flagged`,
                                                        { folder: ordnerPfad, flagged: !(offene.message as { is_flagged?: boolean } | null)?.is_flagged },
                                                        { csrfToken: csrf },
                                                    ),
                                                )
                                            }
                                            archiveCount={archivKennungen.length}
                                            onArchive={() =>
                                                void amGeoeffneten(
                                                    () =>
                                                        postJson(
                                                            `${basis}/${konto?.id}/bulk`,
                                                            {
                                                                folder: ordnerPfad,
                                                                action: 'archive',
                                                                uids: archivKennungen.length > 0 ? archivKennungen : [offeneKennung()],
                                                            },
                                                            { csrfToken: csrf },
                                                        ),
                                                    true,
                                                )
                                            }
                                            onDelete={() =>
                                                void amGeoeffneten(
                                                    () =>
                                                        postJson(
                                                            `${basis}/${konto?.id}/message/${offeneKennung()}/delete`,
                                                            { folder: ordnerPfad },
                                                            { csrfToken: csrf },
                                                        ),
                                                    true,
                                                )
                                            }
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
                        aside={
                            zeigtAssistent && konto ? (
                                <MailboxSidePanel title="Postfach-Assistent" onClose={assistent.verbergen}>
                                    <MailboxChat account={konto} source={chatQuelle} />
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
            </div>

            {nachDerSeite}
        </>
    );
}
