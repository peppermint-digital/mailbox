import { useState, type ComponentType, type ReactNode } from 'react';
import { Clock, FolderInput, FolderOpen, Globe, UserCheck } from 'lucide-react';
import { looksLikeFolder, ordnerAnzeigename } from './folders';
import { germanLabels } from './germanLabels';
import type { InlineAntwortProps } from './InlineAntwort';
import type { WeiterleitenFeldProps } from './inertia/WeiterleitenFeld';
import { MailAssignmentPicker } from './MailAssignmentPicker';
import { MailboxBrowser } from './MailboxBrowser';
import { MailboxChat, type MailboxChatProps } from './MailboxChat';
import { MailboxHeader, MailboxKontoAuswahl } from './MailboxHeader';
import { MailboxMobilLeiste, MailboxVerfassenKnopf } from './MailboxMobilLeiste';
import { MailboxKuerzelHilfe } from './MailboxKuerzelHilfe';
import { MailboxSidePanel, MailboxSidePanelHandle } from './MailboxSidePanel';
import { Button } from './ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from './ui/dropdown-menu';
import { MailConversationPane } from './MailConversationPane';
import { MailFolderList } from './MailFolderList';
import { MailFolderManager } from './MailFolderManager';
import { MailListToolbar, type SuchVerzeichnis } from './MailListToolbar';
import { MailMessageList, type MailMessageListProps } from './MailMessageList';
import { MailMessageView } from './MailMessageView';
import { MailWerkzeugleiste } from './MailWerkzeugleiste';
import { postJson } from './transport';
import { Skeleton } from './ui/skeleton';
import type { MailFolder } from './MailFolderList';
import type { RowMessage } from './rows';
import { WIEDERVORLAGE_ZEITEN, type MailboxSeiteErgebnis, type MailboxSeiteKonto } from './useMailboxSeite';
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
 * | `zusatzAktionen` | — (das Schlummer-Menü zeichnet seit A13 diese Fläche) | — | „Aus der Ablage entfernen" |
 * | `nachDerSeite` | — | — | der Entfernen-Dialog |
 * | `werkzeugeVerbergen` | eigene Verben aus | eigene Verben aus | nichts aus |
 * | `chatZusatz` | — | — | — |
 *
 * `chatZusatz` steht noch leer, weil es fuer den Projekt-Manager ergaenzt
 * wurde (#9116): Dort haengt eine Spracheingabe am Assistenten, und ohne
 * diesen Durchgang haette der Umstieg auf diese Flaeche sie gekostet.
 *
 * ## Korrektur vom 30.10.2026: das Schlummer-Menü ist KEIN Einschnitt mehr
 *
 * Hier stand: *„Es braucht ein Ausklapp-Menü, und das kommt in allen drei
 * Produkten aus deren eigenem UI-Kit. Es hierher zu holen hieße, eine weitere
 * Abhängigkeit aufzunehmen, um rund zwanzig Zeilen je Produkt zu sparen."*
 *
 * Die Begründung war am 22.10.2026 richtig und ist es seit A1/A7 nicht mehr:
 * Diese Fläche zeichnet den Verschieben-Knopf und das Zeilenmenü der
 * Wiedervorlage längst mit `./ui/dropdown-menu`. Die Abhängigkeit, die
 * damals der Preis gewesen wäre, ist zweimal schon bezahlt.
 *
 * Und der zweite Halbsatz — die drei Zeitpunkte seien „eine Entscheidung und
 * keine Darstellung" — hat genau den Schaden angerichtet, gegen den er
 * argumentierte: „Heute Abend" hieß in AI Brain +1 Stunde und im Projekt-
 * Manager den nächsten Tag. Eine Entscheidung, die jedes Produkt für sich
 * trifft, ist in vier Produkten vier Entscheidungen.
 *
 * Seit A13 steht der Knopf hier, mit den Zeitpunkten aus
 * {@link WIEDERVORLAGE_ZEITEN}.
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
    /**
     * Was neben die Knoepfe der Leseansicht gehoert.
     *
     * Das Schlummer-Menue stand bis zum 30.10.2026 hier — in CRM, Verwaltung
     * und AI Brain wortgleich, je rund zwanzig Zeilen. Es zeichnet die Flaeche
     * jetzt selbst (A13, #9116); dieser Platz bleibt fuer das, was wirklich
     * nur ein Produkt hat, etwa „Aus der Ablage entfernen" in AI Brain.
     *
     * ⚠️ Wer auf diese Version zieht, loescht im selben Zug seinen eigenen
     * Uhr-Knopf — sonst stehen zwei da.
     */
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
    /**
     * Der Zustand des Suchverzeichnisses, aus dem die Treffer kommen (#9116).
     *
     * Hier standen bis zum 30.10.2026 zwei Steckplaetze — `leisteZusatz` fuer
     * eigene Knoepfe in der Listenleiste und `leisteHinweis` fuer eine Zeile
     * darunter. Beide hatte genau EIN Produkt gefuellt, und genau darin lag
     * der Fehler: Was in einem Steckplatz liegt, sieht in jedem Produkt
     * anders aus oder fehlt.
     *
     * Was dort lag, liegt jetzt im Paket: „nur mir zugewiesene" an
     * `eigeneAdresse` (A3), die Tastenkuerzel-Hilfe an der Taste, die dieses
     * Paket ohnehin bindet (A4) — und dieser Hinweis, gezeichnet aus Zahlen.
     *
     * Das Produkt liefert die Zahlen, weil nur es das Verzeichnis fuehrt. Wie
     * sie aussehen, entscheidet das Paket, weil es in allen vier Produkten
     * gleich aussehen soll.
     */
    suchVerzeichnis?: SuchVerzeichnis | null;
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
    suchVerzeichnis = null,
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
        aufwecken,
        auswahl,
        beschaeftigt,
        chatQuelle,
        csrf,
        fehler,
        filterOffen,
        gruppierungUmschalten,
        konto,
        liste,
        melden,
        hilfeOffen,
        nachrichtBeschaeftigt,
        nurEigene,
        nurEigeneMoeglich,
        nurEigeneUmschalten,
        setHilfeOffen,
        offeneVerschieben,
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
        suchFeld,
        verlauf,
        weiterleitenOffen,
        werkzeuge,
        zeilen,
        zuweisungen,
    } = seite;

    const zeigtAssistent = chatVerfuegbar && konto !== null && assistent.sichtbar;

    /*
     * Das Zeilenmenue der Wiedervorlage (A7, #9116).
     *
     * Es stand im Manager — 92 Zeilen samt Zeitpunkten und Aufheben —, und
     * CRM, Verwaltung und AI Brain hatten gar keinen Weg, eine Mail aus der
     * LISTE heraus auf spaeter zu legen. AI Brain und Verwaltung konnten es
     * nur an der geoeffneten Nachricht.
     *
     * Eine Nachricht ohne `message_id` kann nicht schlummern: Die
     * Wiedervorlage haengt genau daran. Dann geht das Menue gar nicht erst
     * auf, statt einen Eintrag anzubieten, der nichts tut.
     */
    const [zeilenMenu, setZeilenMenu] = useState<{ x: number; y: number; nachricht: RowMessage } | null>(null);

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
     * Der Uhr-Knopf an der offenen Mail (A13, #9116).
     *
     * Er stand in CRM, Verwaltung und AI Brain in `zusatzAktionen` — dreimal
     * dasselbe Ausklapp-Menue mit denselben drei Zeitpunkten, je rund zwanzig
     * Zeilen. Der Projekt-Manager hatte ihn gar nicht; dort lag die
     * Wiedervorlage nur im Zeilenmenue.
     *
     * Die Begruendung, warum er bis heute DRAUSSEN blieb, stand oben in
     * diesem Kopf: Er braucht ein Ausklapp-Menue, und das kam aus dem UI-Kit
     * des Produkts. Das stimmt nicht mehr — seit A1 zeichnet diese Flaeche den
     * Verschieben-Knopf mit `./ui/dropdown-menu`, und seit A7 das Zeilenmenue
     * der Wiedervorlage. Die Zutat ist also schon da, und zwar zweimal.
     *
     * Dieselben Zeitpunkte wie im Zeilenmenue, aus derselben Konstante: Bis
     * zum 30.10.2026 hiess „heute Abend" in AI Brain +1 Stunde und im Manager
     * den naechsten Tag. Zwei Wege zu einer Sache, die verschieden rechnen,
     * sind schlimmer als ein fehlender Weg.
     */
    const schlummerKnopf = (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    size="sm"
                    variant="outline"
                    title={germanLabels.wiedervorlage.knopf}
                    aria-label={germanLabels.wiedervorlage.knopf}
                    data-slot="mailbox-schlummern"
                >
                    <Clock className="h-4 w-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {WIEDERVORLAGE_ZEITEN.map(({ schluessel, wann }) => (
                    <DropdownMenuItem key={schluessel} onSelect={() => void schlummern(wann())}>
                        {germanLabels.wiedervorlage[schluessel as keyof typeof germanLabels.wiedervorlage] as string}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuItem onSelect={() => void aufwecken()}>{germanLabels.wiedervorlage.aufheben}</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );

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

    /*
     * Woerter fuer die schmale Leiste auf dem Telefon (#9134).
     *
     * Der ORDNERNAME steht dort, wo am Schreibtisch „E-Mails" steht — so macht
     * es Outlook Mobile, und so war es ausdruecklich gewollt. Der Seitentitel
     * steht ohnehin schon in der Navigation des Produkts daneben; ihn hier zu
     * wiederholen kostete eine Zeile und sagte nichts.
     *
     * Faellt der Ordner nicht in der Liste auf (eine Suche ueber alle Ordner,
     * ein Pfad, den der Server noch nicht gemeldet hat), bleibt der Pfad
     * stehen. Ein leerer Titel waere schlimmer als ein technischer.
     */
    const ordnerName = (() => {
        const zeile = (ordner.folders as MailFolder[]).find((f) => f.path === ordnerPfad);

        /*
         * Auch ohne Zeile uebersetzen: Beim ersten Zeichnen ist die Ordnerliste
         * noch nicht da, und „INBOX" stand genau dann in der Leiste.
         */
        return ordnerAnzeigename(zeile ?? { path: ordnerPfad }) || titel;
    })();

    const offeneAbsender = (() => {
        const n = offene.message as { from_name?: string; from_address?: string } | null;

        return n ? n.from_name || n.from_address : undefined;
    })();

    /*
     * #9134: Die Kopfzeile wandert in den `toolbar`-Platz des Rahmens.
     *
     * Bis zum 30.10.2026 stand sie UEBER `MailboxBrowser` — und damit
     * ausserhalb von {@see MailboxPaneContext}. Sie konnte deshalb nicht
     * wissen, ob auf dem Telefon gerade die Liste oder eine geoeffnete Mail
     * den Bildschirm fuellt, und stand in beiden Faellen gleich hoch im Weg.
     * Der Platz dafuer war im Rahmen laengst vorgesehen; benutzt hat ihn nur
     * niemand.
     *
     * Am Schreibtisch aendert das NICHTS: Der Rahmen zeichnet `toolbar` als
     * erstes Kind seiner Spalte, also genau dort, wo die Kopfzeile vorher
     * stand.
     */
    const kopfzeile = (
        <>
            <div className="max-md:hidden">
            <MailboxHeader
                title={titel}
                labels={germanLabels.header}
                accounts={konten.map((k) => ({ id: k.id, name: k.name, geteilt: k.geteilt }))}
                /*
                 * A5 (#9116): Der Globus kommt aus dem Konto selbst, nicht
                 * mehr als durchgereichte Komponente. Der Manager hatte
                 * ihn, die anderen drei nicht — dabei haben CRM und
                 * Verwaltung ebenfalls geteilte Postfaecher.
                 *
                 * Die Angabe reist MIT dem Konto, statt sie hier wieder
                 * nachzuschlagen: Ein Nachschlagen braeuchte einen
                 * Vergleich von Kennungen, und der waere ein Schutz, den
                 * keine Mutationsprobe treffen kann — weil beide Listen
                 * ohnehin dieselben Objekte tragen.
                 */
                accountAccessory={(k) =>
                    k.geteilt ? <Globe className="h-4 w-4 shrink-0 text-muted-foreground" data-slot="mailbox-geteilt" /> : null
                }
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
            </div>

            {/*
                Und darunter dieselbe Sache fuer das Telefon — EIN Band statt
                drei. Welche der beiden man sieht, entscheidet die Klasse, nicht
                JavaScript (siehe {@see MailboxMobilLeiste}).
            */}
            <MailboxMobilLeiste
                ordnerName={ordnerName}
                absender={offeneAbsender}
                hatOrdner={ordnerSichtbar}
                onAssistent={chatVerfuegbar && konto ? assistent.zeigen : undefined}
                onAdressbuch={() => navigieren(`${basis}/address-book`)}
                onAktualisieren={
                    konto ? () => void liste.load({ accountId: konto.id, folder: ordnerPfad, page: liste.page, refresh: true }) : undefined
                }
                aktualisiert={liste.refreshing}
            />
        </>
    );

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

                {konten.length === 0 ? (
                    <div className="flex flex-1 items-center justify-center text-muted-foreground">
                        <p>{leerText}</p>
                    </div>
                ) : (
                    <MailboxBrowser
                        toolbar={kopfzeile}
                        /*
                         * Das Band mit „Ordner" / „Zurueck zur Liste" faellt
                         * weg: Die schmale Leiste oben traegt beides laengst.
                         * Zwei Baender uebereinander waren 45 px, die auf einem
                         * Telefon zaehlen.
                         */
                        eigeneNavigation
                        /*
                         * Der schwebende Verfassen-Knopf gehoert IN den Rahmen:
                         * Dort legt er sich auf die Flaechen statt auf das
                         * Fenster, und er sieht, welche Flaeche gerade vorn ist
                         * — beim Lesen nimmt er sich selbst weg.
                         */
                        schwebend={
                            konto ? (
                                <MailboxVerfassenKnopf
                                    label={germanLabels.header.compose}
                                    onClick={() => navigieren(`${basis}/compose`)}
                                />
                            ) : undefined
                        }
                        /*
                         * In der Schublade steht das Konto ueber den Ordnern.
                         * Beides beantwortet dieselbe Frage — „wo bin ich" —,
                         * und am Telefon ist dafuer genau ein Ort da.
                         */
                        schubladeKopf={
                            <MailboxKontoAuswahl
                                accounts={konten.map((k) => ({ id: k.id, name: k.name, geteilt: k.geteilt }))}
                                accountId={konto?.id ?? null}
                                onAccountChange={(wert) => setKonto(konten.find((k) => k.id === Number(wert)) ?? null)}
                                labels={germanLabels.header}
                                accountAccessory={(k) =>
                                    k.geteilt ? <Globe className="h-4 w-4 shrink-0 text-muted-foreground" /> : null
                                }
                                className="w-full"
                            />
                        }
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
                                    /*
                                     * A3 (#9116): Erscheint nur, wenn das Produkt
                                     * `eigeneAdresse` mitgegeben hat — sonst wuesste der
                                     * Schalter nicht, was „mir" heisst.
                                     */
                                    mineOnly={nurEigene}
                                    onToggleMine={nurEigeneMoeglich ? nurEigeneUmschalten : undefined}
                                    onHilfe={() => setHilfeOffen(true)}
                                    suchFeldRef={suchFeld}
                                    verzeichnis={suchVerzeichnis}
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
                                            ? () => void gruppierungUmschalten(!liste.grouped, konto?.id ?? '', ordnerPfad)
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
                                    Nachrichten — die Leiste darueber bleibt.

                                    `max-md:pb-24` gehoert genau HIERHIN und nicht an die Liste
                                    (#9134, Befund aus dem Rollout am 30.10.2026): Der runde
                                    Verfassen-Knopf schwebt ueber der letzten sichtbaren Zeile
                                    und deckte deren Oeffnen-Knopf (56 x 40 px, gemessen im CRM).
                                    Ein Polster am Bauteil `MailMessageList` haette nicht
                                    geholfen — gescrollt wird hier, also muss hier der Platz
                                    entstehen, damit die letzte Zeile unter dem Knopf
                                    hervorscrollen kann. 6rem = der Knopf (56) plus sein Abstand
                                    zum Rand (20) plus Luft. */}
                                <div className="min-h-0 flex-1 overflow-y-auto max-md:pb-24">
                                    <MailMessageList<RowMessage>
                                        rows={sichtbareZeilen(zeilen)}
                                        isSearchMode={suche.isSearchMode}
                                        labels={germanLabels.list}
                                        onRowContextMenu={(ereignis, nachricht) => {
                                            ereignis.preventDefault();

                                            if (!nachricht.message_id) {
                                                return;
                                            }

                                            setZeilenMenu({ x: ereignis.clientX, y: ereignis.clientY, nachricht });
                                        }}
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
                                             * #9134: Auf dem Telefon klebt die Knopfleiste
                                             * unten am Rand. Die Flaeche darf das zusichern,
                                             * weil der Rahmen unterhalb von `md` genau EINE
                                             * Flaeche zeigt — die Leseansicht IST dort der
                                             * ganze Bildschirm.
                                             */
                                            aktionenUnten
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
                                            extraActions={
                                                <>
                                                    {schlummerKnopf}
                                                    {zusatzAktionen}
                                                </>
                                            }
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

                {/*
                    Der schwebende Griff bleibt dem Schreibtisch (#9134).

                    Am Telefon sass er unten rechts — und dort sitzt seit heute
                    der Verfassen-Knopf. Zwei schwebende Knoepfe an derselben
                    Ecke sind woertlich Bastians „Buttons uebereinander". Der
                    Assistent ist dort ueber die schmale Leiste erreichbar, und
                    zwar an jeder Stelle, nicht nur wenn er gerade zu ist.
                */}
                {chatVerfuegbar && konto && !assistent.sichtbar && (
                    <div className="max-md:hidden">
                        <MailboxSidePanelHandle label="AI-Brain-Chat" onClick={assistent.zeigen} />
                    </div>
                )}



                {/*
                    Hier stand bis zum 30.10.2026 ein ZWEITER schwebender Griff,
                    fuer den Fall, dass der Assistent nur per Vorgabe offen
                    steht und auf schmalen Schirmen deshalb weg ist
                    (`asideGewaehlt`). Er ist mit #9134 entfallen: Die schmale
                    Leiste hat den Assistenten als festen Knopf, in jedem
                    Zustand und ohne etwas zu verdecken. Zwei Griffe fuer eine
                    Sache waren ohnehin einer zu viel.
                */}
            </div>

            {/* A7 (#9116): Das Zeilenmenue der Wiedervorlage.
                Die Flaeche darunter faengt den Klick daneben UND den naechsten
                Rechtsklick ab — sonst stapeln sich zwei Menues. */}
            {zeilenMenu && (
                <div
                    className="fixed inset-0 z-50"
                    onClick={() => setZeilenMenu(null)}
                    onContextMenu={(ereignis) => {
                        ereignis.preventDefault();
                        setZeilenMenu(null);
                    }}
                >
                    <div
                        className="absolute min-w-56 rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
                        style={{ left: zeilenMenu.x, top: zeilenMenu.y }}
                        onClick={(ereignis) => ereignis.stopPropagation()}
                        data-slot="mailbox-zeilenmenue"
                    >
                        <div className="px-2 py-1.5 text-xs font-medium text-muted-foreground">{germanLabels.wiedervorlage.titel}</div>

                        {WIEDERVORLAGE_ZEITEN.map(({ schluessel, wann }) => (
                            <button
                                key={schluessel}
                                type="button"
                                className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                                onClick={() => {
                                    const ziel = zeilenMenu.nachricht;
                                    setZeilenMenu(null);
                                    void schlummern(wann(), ziel);
                                }}
                            >
                                <Clock className="h-4 w-4 shrink-0 text-muted-foreground" />
                                <span className="truncate">
                                    {germanLabels.wiedervorlage[schluessel as keyof typeof germanLabels.wiedervorlage]}
                                </span>
                            </button>
                        ))}

                        <button
                            type="button"
                            className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                            onClick={() => {
                                const ziel = zeilenMenu.nachricht;
                                setZeilenMenu(null);
                                void aufwecken(ziel);
                            }}
                        >
                            <span className="h-4 w-4 shrink-0" />
                            <span className="truncate text-muted-foreground">{germanLabels.wiedervorlage.aufheben}</span>
                        </button>
                    </div>
                </div>
            )}

            {/* A4 (#9116): Die Hilfe zur Taste `?` — gezeichnet, wo die Taste
                gebunden wird. */}
            <MailboxKuerzelHilfe offen={hilfeOffen} onOffenChange={setHilfeOffen} />

            {nachDerSeite}
        </>
    );
}
