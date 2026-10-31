import {
    ArrowUpRight,
    ChevronDown,
    CircleAlert,
    FileText,
    Paperclip,
    ListChecks,
    Loader2,
    Receipt,
    Ticket,
    TriangleAlert,
    User,
    UserCheck,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import type { MailWerkzeug, MailWerkzeugFeld } from './useMailWerkzeuge';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { cn } from './ui/utils';

/**
 * Eine Werkzeugleiste für ALLE Systeme, an der geöffneten Mail (#8313/#8316).
 *
 * ## Was sie ersetzt
 *
 * Bis zum 20.10.2026 hatte jedes Produkt seine eigene Leiste an derselben
 * Stelle: das CRM eine `MailboxContactBar`, die Verwaltung eine
 * `MailboxTicketBar` und eine `MailboxAttachmentBar`, der Manager seinen
 * Aufgaben-Knopf. Jede kannte genau ein Verb — ihr eigenes.
 *
 * Die Folge war nicht Vielfalt, sondern Blindheit: Wer eine Mail im CRM offen
 * hatte, konnte dort nicht sehen, dass sie im Manager längst an „Angebot
 * Meier überarbeiten" hängt. Er hängte sie ein zweites Mal an eine andere
 * Aufgabe, und niemand bekam eine Meldung.
 *
 * Diese Leiste zeigt alle Verben, die dieser Person an dieser Mail offenstehen
 * — eigene und die der Nachbarsysteme, in einer Reihe, mit dem Namen des
 * Systems am Verb, wenn es ein fremdes ist.
 *
 * ## Die eine Regel, von der alles andere hängt
 *
 * **Eine leere Leiste und eine ausgefallene Leiste dürfen nicht gleich
 * aussehen.**
 *
 * „Keine Werkzeuge" heißt: hier ist nichts zu tun. Antwortet die Verwaltung
 * gerade nicht, ist die Wahrheit „das Ticket-System antwortet nicht" — und
 * jemand, der stattdessen ein leeres Nichts sieht, legt sein Ticket von Hand
 * ein zweites Mal an.
 *
 * Dieses Paket hat dieselbe Regel zweimal bezahlt: bei der Zuständigkeit
 * („leere Antwort ≠ leerer Bestand", 503 statt stiller Leere) und bei der
 * Massenaktion (ein Teilerfolg, der als „nicht erreichbar" ankam). Hier steht
 * sie zum dritten Mal, und deshalb ist sie der Grund für die drei getrennten
 * Zeilen unten: Werkzeuge, `unerreichbar`, `gescheitert`.
 *
 * ## Warum ein Verweis-Verb ein Link ist und kein Knopf
 *
 * Ein Kontakt wird aus zwölftausend gewählt, eine Aufgabe braucht Projekt und
 * Bearbeiter. Diesen Auswähler kann ein fremdes System nicht zeichnen, und zu
 * raten legt Arbeit in die falsche Schublade. Also: Wer `url` trägt, führt
 * hin. Das sieht man am Pfeil, und es ist dieselbe Entscheidung, die der
 * Server in `fuehrtHin()` trifft — hier nur sichtbar gemacht.
 *
 * Das ist der Stand, den #9145 abbaut: Ein Verb soll seine Maske HIER öffnen,
 * statt wegzuführen. Seit v0.194.0 geht das für Felder, die diese Ansicht
 * zeichnen kann (`select`, `text`, `anhang`); der Auswähler mit Suche kommt
 * in v0.195.0, und erst danach ist ein Verweis-Verb die Ausnahme statt der
 * Regel. Bis dahin stehen beide Wege nebeneinander — nicht aus Unschlüssigkeit,
 * sondern weil ein Verb, dessen Angabe hier niemand erfassen kann, lieber
 * hinführt als zuverlässig zu scheitern.
 *
 * ## Warum die Felder in einer Maske stehen und nicht neben dem Knopf
 *
 * Bis v0.193.x stand das eine zeichenbare Feld (`select`) INLINE neben seinem
 * Knopf. Das ging, solange es eine Klappliste war, und hört genau dann auf:
 *
 *   - Eine Anhangwahl ist eine Liste mit Haken. Sie hat keine Zeilenhöhe.
 *   - Zwei Felder an einem Verb schieben die Nachbarverben aus der Reihe.
 *   - Am Telefon geht es nicht auf, und das ist Arithmetik und keine
 *     Geschmacksfrage: Eine Klappliste war `max-w-[12rem]`, also bis 192 px.
 *     Bei 320 px Gerätebreite bleiben in der Leiste rund 296 px — eine Liste
 *     dieser Breite kann nicht neben ihrem eigenen Knopf stehen.
 *
 * Gemessen wird seither die Maske selbst: `handy-layout.test.ts` führt eine
 * fünfte Fassung („werkzeuge") mit aufgeklappter Leiste und offener Maske
 * durch 320 bis 430 px. Dort ist die Leiste 57 px hoch, nichts läuft
 * waagerecht über, nichts überlappt. Eine eigene Fassung, weil die offene
 * Maske den Mailtext nach unten schiebt (573 px statt 369) — und „der Text
 * fängt im oberen Drittel an" ist eine Zusicherung über die ZUGEKLAPPTE
 * Leiste, also über den Zustand, in dem jemand eine Mail öffnet.
 *
 * Also: Ein Verb mit Feldern ist in der Reihe EIN Knopf, und der klappt seine
 * Maske unter der Reihe auf. Eine Maske zur Zeit — zwei halbgefüllte Masken
 * nebeneinander sind eine Gelegenheit, die Angabe ins falsche Verb zu tippen.
 *
 * Was eingegeben wurde, bleibt beim Schließen stehen (je Verb, nach Schlüssel
 * UND System). Wer die Maske zumacht, um in der Mail etwas nachzulesen, hat
 * sonst beim Wiederaufmachen ein leeres Formular — und das ist der Moment, in
 * dem jemand aufgibt und doch ins andere System springt.
 */

/**
 * Ein Anhang der offenen Mail — so wenig, wie die Leiste braucht.
 *
 * Absichtlich nicht `MailMessageViewAttachment`: Die Leiste braucht Namen und
 * Größe, nicht Index und MIME-Art. Der engere Typ nimmt auch Anhänge an, die
 * ein Produkt aus einer anderen Quelle hat (etwa einem Entwurf), ohne dass
 * dort etwas umgebaut werden muss.
 */
export interface WerkzeugAnhang {
    filename: string;
    size?: number | null;
}

/**
 * Feldarten, die diese Ansicht zeichnen kann.
 *
 * Als Liste und nicht als `switch` im Zeichnen: Die Frage „kann ich dieses
 * Verb anbieten?" wird VOR dem Zeichnen gestellt (`zeichenbar`), und beide
 * Stellen müssen dieselbe Antwort geben. Zwei Rechnungen hatten wir schon
 * einmal — siehe `werteFuer`.
 */
const ZEICHENBARE_ARTEN = ['select', 'text', 'anhang'] as const;

/**
 * Woran die Ansicht ein Verb festmacht: Schlüssel UND System.
 *
 * Der Schlüssel allein ist nur im eigenen System eindeutig — „Aufgabe
 * anhängen" gibt es im Manager und in der Verwaltung, und das sind zwei
 * verschiedene Dinge. Stand vorher dreimal als Ausdruck im Zeichnen; jetzt
 * einmal, weil inzwischen drei Zustände daran hängen (Eingaben, offene Maske,
 * offene Rückfrage) und ein Auseinanderlaufen hieße: Die Maske von A zeigt die
 * Eingaben von B.
 */
function kennungVon(werkzeug: MailWerkzeug): string {
    return werkzeug.key + (werkzeug.system ?? '');
}

/** Die Wörter. Vorgaben auf Deutsch; `germanLabels.werkzeuge` reicht sie herein. */
export interface MailWerkzeugleisteLabels {
    titel: string;
    leer: string;
    laedt: string;
    /** `(systeme) => …` — welche Systeme nicht geantwortet haben. */
    unerreichbar: (systeme: string[]) => string;
    /** `(verben) => …` — welche Verben geantwortet und gescheitert sind. */
    gescheitert: (verben: string[]) => string;
    nichtGefragt: string;
    laeuft: string;
    /**
     * `(verben) => …` — Verben, deren Eingabe diese Ansicht nicht zeichnen kann.
     *
     * Kein toter Knopf und kein stilles Weglassen: Das eine scheitert
     * zuverlässig, das andere sieht aus wie „gibt es nicht".
     */
    nichtZeichenbar: (verben: string[]) => string;
    /** Was in einer leeren Auswahl steht, solange niemand gewählt hat. */
    bitteWaehlen: string;
    /** Der Knopf, der die offene Maske wieder zumacht. */
    abbrechen: string;
    /** Steht in der Maske, wenn ein `anhang`-Feld nichts zur Wahl hat. */
    keineAnhaenge: string;
    /** Der bestätigende Knopf einer Rückfrage. */
    rueckfrageJa: string;
    /** `(bytes) => …` — die Größe eines Anhangs, in Worten des Produkts. */
    groesse: (bytes: number) => string;
}

export const WERKZEUGLEISTE_VORGABEN: MailWerkzeugleisteLabels = {
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
    abbrechen: 'Abbrechen',
    keineAnhaenge: 'Diese Nachricht hat keine Anhänge.',
    rueckfrageJa: 'Ja, ausführen',
    groesse: (bytes) => (bytes < 1024 ? `${bytes} B` : bytes < 1024 * 1024 ? `${(bytes / 1024).toFixed(1)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`),
};

/**
 * Symbole für die Verben, die es heute gibt.
 *
 * Als Zuordnung und nicht als freie Angabe aus dem Server: Ein Produkt
 * schickt `'ticket'`, und welches Bildchen das ist, entscheidet die
 * Oberfläche. Ein unbekannter Name bekommt den Schraubenschlüssel — ein
 * fehlendes Symbol darf kein leeres Loch sein.
 */
const SYMBOLE: Record<string, typeof Wrench> = {
    ticket: Ticket,
    receipt: Receipt,
    'file-text': FileText,
    'list-checks': ListChecks,
    user: User,
    'user-check': UserCheck,
    wrench: Wrench,
};

export interface MailWerkzeugleisteProps {
    werkzeuge: MailWerkzeug[];
    unerreichbar: string[];
    gescheitert: string[];
    laedt: boolean;
    leerAberVollstaendig: boolean;
    erreicht: boolean | null;
    ausloesen: (werkzeug: MailWerkzeug, eingabe?: Record<string, unknown>) => Promise<{ ok: boolean; message: string }>;
    /** Wird nach einem ausgelösten Verb mit dem Satz des Systems gerufen. */
    onMeldung?: (meldung: string, gelungen: boolean) => void;
    /** Inertia-Anwendungen reichen hier ihren Router herein. */
    onNavigate?: (href: string) => void;
    /**
     * Verben, die dieses Produkt schon selbst zeichnet.
     *
     * Ein Produkt MUSS sein Verb im Verzeichnis anmelden, damit die anderen
     * Systeme es sehen — aber es kann es selbst besser koennen. Das CRM hat
     * eine eigene Kontaktleiste mit Suche, Verknuepfen und Loesen; das Verb
     * `kontakt` fuehrt dagegen nur ins CRM. Beides nebeneinander zu zeigen
     * waere dieselbe Sache zweimal, einmal schlechter.
     *
     * Ein Praedikat und keine Liste von Schluesseln: Die Entscheidung haengt
     * oft am System („alles Eigene zeichne ich selbst"), nicht am einzelnen
     * Verb.
     *
     * Ausblenden heisst NICHT abmelden. Die anderen Systeme sehen das Verb
     * weiter — hier verschwindet nur der doppelte Knopf.
     */
    verbergen?: (werkzeug: MailWerkzeug) => boolean;
    /**
     * Verben, die dieses Produkt selbst BEDIENT — Schlüssel → Handler.
     *
     * Der Unterschied zu `verbergen` ist der Punkt: Verbergen heisst „ich
     * zeichne das woanders", Beanspruchen heisst „der Knopf bleibt hier, aber
     * er öffnet meinen Dialog".
     *
     * Der Fall, für den es gebaut ist (#8434): Der Manager hat für „Aufgabe
     * anhängen" einen Dialog mit Suche und Anhangwahl — den kann ein fremdes
     * System nicht zeichnen. Bisher blendete er das Verb deshalb aus und setzte
     * einen EIGENEN Knopf neben den Betreff. Damit stand dieselbe Sache zweimal
     * im Bild, unter zwei Namen („Aufgabe anhängen" in der Leiste der Nachbarn,
     * „Aufgabe zuordnen" am Titel), und die Werkzeugleiste war nicht mehr der
     * Ort, an dem Werkzeuge stehen.
     *
     * Der Nutzer hat genau das gemeldet: „Das wäre ja auch wieder eine
     * Werkzeugaktion und keine Aktion im E-Mail-Feld."
     *
     * Beansprucht wird nur das Auslösen. Das Verb bleibt angemeldet, trägt
     * seinen Namen, seine Reihenfolge und sein Symbol aus dem Verzeichnis —
     * damit es im eigenen System genauso heisst wie bei den Nachbarn.
     */
    eigene?: Record<string, () => void>;
    /**
     * Die Anhänge der offenen Mail — für Felder der Art `anhang`.
     *
     * Kommt von der Fläche und nicht vom Verb: Die Anhänge kennt das Paket
     * schon, es zeigt sie an. Sie noch einmal von jedem Produkt einsammeln zu
     * lassen wäre dieselbe Liste aus einer schlechteren Quelle — das Produkt
     * müsste die Mail dafür selbst holen, und ein fremdes System kann das gar
     * nicht.
     *
     * Fehlt die Angabe, hat ein `anhang`-Feld nichts zur Wahl. Dann ist der
     * Knopf aus und die Maske sagt es. Nicht: Knopf weg — das sähe aus, als
     * gäbe es das Verb hier nicht.
     */
    anhaenge?: WerkzeugAnhang[];
    labels?: Partial<MailWerkzeugleisteLabels>;
    className?: string;
}

export function MailWerkzeugleiste({
    werkzeuge,
    unerreichbar,
    gescheitert,
    laedt,
    leerAberVollstaendig,
    erreicht,
    ausloesen,
    onMeldung,
    onNavigate,
    verbergen,
    eigene,
    anhaenge,
    labels,
    className,
}: MailWerkzeugleisteProps) {
    const texte = { ...WERKZEUGLEISTE_VORGABEN, ...labels };
    const [laufend, setLaufend] = useState<string | null>(null);

    /*
     * Auf dem Telefon ist diese Leiste zugeklappt (#9134).
     *
     * Gemessen am 30.10.2026 an Bastians Bild (CRM, iPhone 14): aufgeklappt
     * rund 180 px von 844 — und das ueber der Mail, die man lesen wollte. Die
     * Verben sind richtig und sollen bleiben; sie sind nur nicht das, weswegen
     * jemand eine Mail oeffnet.
     *
     * Der Zustand steht hier und nicht in einer Klasse, weil es ein Zustand
     * IST: Wer aufklappt, will es offen sehen. Dass es den Zustand am
     * Schreibtisch nicht gibt, erledigt `max-md:hidden` — dort ist die Leiste
     * immer offen, egal was hier steht. Kein `matchMedia`, kein zweiter Stand,
     * der beim ersten Zeichnen noch nicht stimmt.
     */
    const [offen, setOffen] = useState(false);

    /**
     * Was in den Feldern steht, je Werkzeug.
     *
     * Nach Kennung (Schlüssel + System) und nicht nach Schlüssel allein:
     * „Aufgabe anhängen" gibt es in zwei Systemen, und das sind zwei Verben.
     */
    const [eingaben, setEingaben] = useState<Record<string, Record<string, unknown>>>({});

    /**
     * Welche Maske offen ist — eine Kennung oder keine.
     *
     * Ein einzelner Wert und keine Menge: Mehr als eine offene Maske ist kein
     * Komfort, sondern die Gelegenheit, die Angabe ins falsche Verb zu tippen.
     * Die EINGABEN liegen daneben in `eingaben` und bleiben beim Schließen
     * stehen; offen ist nur, was man gerade sieht.
     */
    const [maske, setMaske] = useState<string | null>(null);

    /**
     * Welches Verb gerade nachfragt.
     *
     * Hier und nicht in `globalThis.confirm`: Ein Browser-Dialog hält den
     * ganzen Reiter an, lässt sich nicht gestalten, nicht übersetzen und in
     * einer Inertia-Anwendung nicht testen. Vor allem aber ist er genau das,
     * was dieser Task abbaut — eine Frage, die NICHT in der Leiste steht.
     */
    const [rueckfrage, setRueckfrage] = useState<string | null>(null);

    const sichtbar = verbergen ? werkzeuge.filter((w) => !verbergen(w)) : werkzeuge;

    /*
     * Verben, deren Pflichteingabe diese Ansicht nicht erfassen kann.
     *
     * Sie werden NICHT als Knopf angeboten — ein Verb, das seine Angabe nicht
     * einsammeln kann, scheitert drüben zuverlässig. Aber sie werden auch
     * nicht verschwiegen: Ein stilles Weglassen sieht aus wie „gibt es nicht",
     * und dann sucht jemand den Fehler bei sich.
     */
    const zeichenbar = (werkzeug: MailWerkzeug): boolean =>
        (werkzeug.fields ?? []).every((feld) => (ZEICHENBARE_ARTEN as readonly string[]).includes(feld.type));

    /**
     * Die WIRKSAMEN Werte eines Verbs: was der Mensch gewählt hat, sonst die
     * Vorbelegung des Servers.
     *
     * Eine Funktion und keine zwei Rechnungen — das Zeichnen und der Klick
     * müssen dasselbe ergeben. Der erste Entwurf hatte zwei: Die Leiste
     * ZEIGTE die vorbelegte Person und SCHICKTE ein leeres Objekt, weil die
     * Vorbelegung nur im Zeichnen stand. Der Server hätte korrekt „es wurde
     * niemand ausgewählt" geantwortet, während im Feld jemand stand.
     *
     * `??` je Feld: Wer eines von zwei Feldern angefasst hat, soll das andere
     * weiter vorbelegt sehen — und mitschicken.
     */
    function werteFuer(werkzeug: MailWerkzeug): Record<string, unknown> {
        const kennung = kennungVon(werkzeug);
        const werte: Record<string, unknown> = {};

        for (const feld of werkzeug.fields ?? []) {
            const angefasst = eingaben[kennung]?.[feld.name];

            /*
             * Ein Mehrfachfeld trägt eine LISTE, auch wenn nur ein Haken
             * steht — und eine leere Liste, wenn keiner steht. Das darf nicht
             * zu `''` zusammenfallen: Der Server unterscheidet „nichts
             * gewählt" von „ein Feld, das es nicht gab", und `''` liest sich
             * als Zweites.
             */
            if (feld.multiple) {
                werte[feld.name] = Array.isArray(angefasst) ? angefasst : feld.value ? [feld.value] : [];

                continue;
            }

            werte[feld.name] = (angefasst as string | undefined) ?? feld.value ?? '';
        }

        return werte;
    }

    /** Was in einem Feld steht, als Text — fürs Zeichnen einfacher Felder. */
    function textWert(werkzeug: MailWerkzeug, feldName: string): string {
        const wert = werteFuer(werkzeug)[feldName];

        return typeof wert === 'string' ? wert : '';
    }

    /** Die gehakten Werte eines Mehrfachfeldes. */
    function listeWert(werkzeug: MailWerkzeug, feldName: string): string[] {
        const wert = werteFuer(werkzeug)[feldName];

        return Array.isArray(wert) ? (wert as string[]) : [];
    }

    function setzeWert(kennung: string, feldName: string, wert: unknown): void {
        setEingaben((vorher) => ({
            ...vorher,
            [kennung]: { ...(vorher[kennung] ?? {}), [feldName]: wert },
        }));
    }

    /**
     * Fehlt an diesem Verb eine Pflichtangabe?
     *
     * Eine Stelle für alle Feldarten, weil der KNOPF davon abhängt und der
     * Knopf nur einmal existiert. Beim Mehrfachfeld ist „leere Liste" die
     * fehlende Angabe, nicht `''`.
     */
    function fehltPflichtAn(werkzeug: MailWerkzeug): boolean {
        return (werkzeug.fields ?? []).some((feld) => {
            if (!feld.required) {
                return false;
            }

            const wert = werteFuer(werkzeug)[feld.name];

            return Array.isArray(wert) ? wert.length === 0 : (wert ?? '') === '';
        });
    }

    const nichtZeichenbar = sichtbar.filter((w) => !w.url && !w.state && !zeichenbar(w));

    /*
     * Das Verb, dessen Maske offen ist — aus `sichtbar` gesucht und nicht
     * gemerkt.
     *
     * Der Unterschied zaehlt: Die Werkzeuge kommen bei jedem Oeffnen einer
     * Mail neu, und ein gemerktes Verb waere dann das von GESTERN — mit dem
     * Zustand und den Feldern der vorigen Nachricht. Gemerkt wird nur die
     * Kennung; welches Verb dazu gehoert, entscheidet die aktuelle Liste.
     * Ist es nicht mehr dabei, ist die Maske zu, und das ist richtig.
     */
    const offeneMaske = maske ? (sichtbar.find((w) => kennungVon(w) === maske) ?? null) : null;
    const offeneRueckfrage = rueckfrage ? (sichtbar.find((w) => kennungVon(w) === rueckfrage) ?? null) : null;

    /*
     * `leerAberVollstaendig` bleibt die Auskunft des Hakens und wird NICHT
     * aus `sichtbar.length` neu berechnet. Ein Produkt, das sein eigenes Verb
     * ausblendet, hat dadurch nicht „nichts zu tun" — es zeigt es nur
     * woanders. Der Satz „fuer diese Nachricht ist nichts zu tun" gilt nur,
     * wenn der SERVER nichts hatte.
     */
    const darfLeerSagen = leerAberVollstaendig && werkzeuge.length === 0;

    // Noch nicht gefragt: nichts zeigen. Eine Leiste, die beim ersten Rendern
    // „nichts zu tun" behauptet, hat nichts versucht.
    if (erreicht === null && !laedt) {
        return null;
    }

    /**
     * Ein Klick auf das Verb selbst — drei Wege, und die Reihenfolge ist die
     * Entscheidung.
     *
     *   1. Beansprucht das Produkt das Verb, übernimmt es. Ohne alles andere.
     *   2. Hat das Verb Felder, klappt die Maske auf. Ausgelöst wird dort.
     *   3. Sonst: Rückfrage, wenn eine verlangt ist, sonst sofort.
     *
     * Die Maske VOR der Rückfrage: Erst zustimmen und dann ein Formular
     * sehen heißt, zugestimmt zu haben, bevor man wusste, wozu.
     *
     * Der eigene Weg bleibt ganz vorne und OHNE Laufanzeige. Was danach
     * passiert, weiss nur das Produkt: Der Manager öffnet einen Dialog, und
     * ein Knopf, der sich dabei als „wird ausgeführt" markiert und nie fertig
     * wird, sieht aus wie ein Hänger.
     */
    function klickAufVerb(werkzeug: MailWerkzeug): void {
        const eigenerWeg = eigene?.[werkzeug.key];

        if (eigenerWeg) {
            eigenerWeg();

            return;
        }

        const kennung = kennungVon(werkzeug);

        if ((werkzeug.fields ?? []).length > 0) {
            setRueckfrage(null);
            setMaske((offen) => (offen === kennung ? null : kennung));

            return;
        }

        if (werkzeug.confirm) {
            setMaske(null);
            setRueckfrage(kennung);

            return;
        }

        void klick(werkzeug);
    }

    async function klick(werkzeug: MailWerkzeug) {
        const kennung = kennungVon(werkzeug);

        setLaufend(kennung);

        try {
            const ergebnis = await ausloesen(werkzeug, werteFuer(werkzeug));
            onMeldung?.(ergebnis.message, ergebnis.ok);

            if (ergebnis.ok) {
                // Nach einem gelungenen Verb die Auswahl zurücksetzen. Eine
                // stehengebliebene Auswahl liest sich wie „das ist der
                // aktuelle Stand" — der steht aber im Zustand, den der Server
                // neu schickt.
                setEingaben((vorher) => {
                    const naechste = { ...vorher };
                    delete naechste[kennung];

                    return naechste;
                });

                /*
                 * Und die Maske zu. Eine offene Maske nach dem Erfolg liest
                 * sich als „noch nicht abgeschickt", und der zweite Klick
                 * legt das Ticket ein zweites Mal an.
                 *
                 * NUR bei Erfolg: Nach einer Absage bleibt sie offen, mit dem
                 * Eingetippten darin. Wer die Maske im Fehlerfall verliert,
                 * tippt alles neu — und die Absage kam oft genug von einer
                 * Angabe, die man ändern wollte, nicht wegwerfen.
                 */
                setMaske((offen) => (offen === kennung ? null : offen));
            }
        } finally {
            setLaufend(null);
            setRueckfrage((offen) => (offen === kennung ? null : offen));
        }
    }

    return (
        <div data-slot="mail-werkzeugleiste" className={cn('flex flex-col gap-1.5 border-b px-3 py-2', className)}>
            {/*
                Der Aufklapper gibt es nur am Telefon. Ein Knopf und keine
                Ueberschrift: Er tut etwas, also muss man ihn mit der Tastatur
                erreichen und er braucht einen Zustand, den eine Vorlesehilfe
                ansagen kann.
            */}
            <button
                type="button"
                data-slot="mail-werkzeugleiste-aufklapper"
                aria-expanded={offen}
                className="-mx-1 flex min-h-10 items-center gap-1.5 rounded px-1 text-left text-xs font-medium text-muted-foreground md:hidden"
                onClick={() => setOffen((war) => !war)}
            >
                <ChevronDown className={cn('h-4 w-4 shrink-0 transition-transform', offen && 'rotate-180')} />
                {texte.titel}
            </button>

            <div className={cn('flex flex-col gap-1.5', !offen && 'max-md:hidden')} data-slot="mail-werkzeugleiste-inhalt">
            <div className="flex flex-wrap items-center gap-1.5">
                <span className="mr-1 text-xs font-medium text-muted-foreground max-md:hidden">{texte.titel}</span>

                {laedt && (
                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                        <Loader2 className="h-3 w-3 animate-spin" />
                        {texte.laedt}
                    </span>
                )}

                {sichtbar.map((werkzeug) => {
                    const Symbol = SYMBOLE[werkzeug.icon ?? ''] ?? Wrench;
                    const kennung = kennungVon(werkzeug);
                    const beschaeftigt = laufend === kennung;

                    /*
                     * Der ZUSTAND schlägt das Verb.
                     *
                     * Gibt es das Ticket schon, ist „Ticket anlegen" die
                     * falsche Einladung — dann gehört dorthin ein Weg zu dem,
                     * was existiert. Das ist der eigentliche Gewinn der
                     * gemeinsamen Leiste: Man sieht es, ohne das andere
                     * System zu öffnen.
                     */
                    if (werkzeug.state) {
                        return werkzeug.state.url ? (
                            <a
                                key={kennung}
                                href={werkzeug.state.url}
                                onClick={(e) => {
                                    if (!onNavigate || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) {
                                        return;
                                    }
                                    e.preventDefault();
                                    onNavigate(werkzeug.state!.url!);
                                }}
                                className="inline-flex items-center gap-1 rounded-md border border-primary/30 bg-primary/5 px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-primary/10"
                            >
                                <Symbol className="h-3.5 w-3.5" />
                                {werkzeug.state.label}
                                {werkzeug.system_label && <Badge variant="outline">{werkzeug.system_label}</Badge>}
                            </a>
                        ) : (
                            <span
                                key={kennung}
                                className="inline-flex items-center gap-1 rounded-md border bg-muted px-2 py-1 text-xs text-muted-foreground"
                            >
                                <Symbol className="h-3.5 w-3.5" />
                                {werkzeug.state.label}
                            </span>
                        );
                    }

                    // Führt hin: ein Link, kein Knopf. Siehe Klassentext.
                    if (werkzeug.url) {
                        return (
                            <a
                                key={kennung}
                                href={werkzeug.url}
                                onClick={(e) => {
                                    if (!onNavigate || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) {
                                        return;
                                    }
                                    e.preventDefault();
                                    onNavigate(werkzeug.url!);
                                }}
                                className="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs transition-colors hover:bg-accent hover:text-accent-foreground"
                            >
                                <Symbol className="h-3.5 w-3.5" />
                                {werkzeug.label}
                                <ArrowUpRight className="h-3 w-3 opacity-60" />
                                {werkzeug.system_label && <Badge variant="outline">{werkzeug.system_label}</Badge>}
                            </a>
                        );
                    }

                    // Eine Angabe, die diese Ansicht nicht erfassen kann:
                    // kein Knopf. Gesagt wird es unten in einer eigenen
                    // Zeile, damit es nicht wie „gibt es nicht" aussieht.
                    if (!zeichenbar(werkzeug)) {
                        return null;
                    }

                    /*
                     * EIN Knopf, auch wenn das Verb Felder hat. Die Felder
                     * stehen in der Maske unter der Reihe — siehe Klassentext.
                     *
                     * `aria-expanded` nur, wenn es etwas aufzuklappen gibt:
                     * An einem Knopf, der sofort tut, wäre es eine Lüge.
                     */
                    const hatFelder = (werkzeug.fields ?? []).length > 0;
                    const istOffen = maske === kennung || rueckfrage === kennung;

                    return (
                        <Button
                            key={kennung}
                            type="button"
                            variant={istOffen ? 'secondary' : 'outline'}
                            size="sm"
                            aria-expanded={hatFelder || werkzeug.confirm ? istOffen : undefined}
                            disabled={beschaeftigt}
                            onClick={() => klickAufVerb(werkzeug)}
                            className="h-7 gap-1 px-2 text-xs"
                        >
                            {beschaeftigt ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Symbol className="h-3.5 w-3.5" />}
                            {beschaeftigt ? texte.laeuft : werkzeug.label}
                            {werkzeug.system_label && <Badge variant="outline">{werkzeug.system_label}</Badge>}
                            {hatFelder && <ChevronDown className={cn('h-3 w-3 opacity-60 transition-transform', istOffen && 'rotate-180')} />}
                        </Button>
                    );
                })}

                {/*
                 * „Nichts zu tun" NUR, wenn auch nichts fehlt. Die Auskunft
                 * kommt aus `leerAberVollstaendig`, nicht aus `length === 0`
                 * — der Unterschied ist der ganze Grund für diese Leiste.
                 */}
                {!laedt && darfLeerSagen && <span className="text-xs text-muted-foreground">{texte.leer}</span>}

                {!laedt && erreicht === false && (
                    <span className="flex items-center gap-1 text-xs text-destructive">
                        <CircleAlert className="h-3.5 w-3.5" />
                        {texte.nichtGefragt}
                    </span>
                )}
            </div>

            {/*
                Die Maske des offenen Verbs — unter der Reihe, nicht in ihr.

                Hier und nicht neben dem Knopf, weil eine Anhangwahl keine
                Zeilenhoehe hat und zwei Felder die Nachbarverben aus der
                Reihe schieben. Siehe Klassentext.
            */}
            {offeneMaske && (
                <div
                    data-slot="mail-werkzeugleiste-maske"
                    className="flex flex-col gap-2 rounded-md border bg-muted/30 p-2"
                >
                    <p className="flex items-center gap-1 text-xs font-medium">
                        {offeneMaske.label}
                        {offeneMaske.system_label && <Badge variant="outline">{offeneMaske.system_label}</Badge>}
                    </p>

                    {(offeneMaske.fields ?? []).map((feld) => (
                        <label key={feld.name} className="flex flex-col gap-1 text-xs">
                            <span className="text-muted-foreground">
                                {feld.label}
                                {feld.required && <span aria-hidden="true"> *</span>}
                            </span>

                            {feld.type === 'anhang' ? (
                                <Anhangwahl
                                    feld={feld}
                                    anhaenge={anhaenge ?? []}
                                    gewaehlt={listeWert(offeneMaske, feld.name)}
                                    einzeln={textWert(offeneMaske, feld.name)}
                                    texte={texte}
                                    onWahl={(wert) => setzeWert(kennungVon(offeneMaske), feld.name, wert)}
                                />
                            ) : feld.type === 'text' ? (
                                <input
                                    type="text"
                                    value={textWert(offeneMaske, feld.name)}
                                    onChange={(e) => setzeWert(kennungVon(offeneMaske), feld.name, e.target.value)}
                                    className="h-8 rounded-md border bg-background px-2 text-xs"
                                />
                            ) : (
                                <select
                                    value={textWert(offeneMaske, feld.name)}
                                    onChange={(e) => setzeWert(kennungVon(offeneMaske), feld.name, e.target.value)}
                                    className="h-8 rounded-md border bg-background px-1.5 text-xs"
                                >
                                    <option value="">{texte.bitteWaehlen}</option>
                                    {(feld.options ?? []).map((wahl) => (
                                        <option key={wahl.value} value={wahl.value}>
                                            {wahl.label}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </label>
                    ))}

                    {/*
                        Die Rueckfrage steht in DERSELBEN Maske, unter den
                        Feldern. Ein zweites Kaestchen fuer eine Frage, deren
                        Gegenstand daruebersteht, waere ein Umweg.
                    */}
                    {offeneMaske.confirm && <p className="text-xs text-amber-700 dark:text-amber-500">{offeneMaske.confirm}</p>}

                    <div className="flex items-center gap-1.5">
                        <Button
                            type="button"
                            size="sm"
                            disabled={laufend === kennungVon(offeneMaske) || fehltPflichtAn(offeneMaske)}
                            onClick={() => void klick(offeneMaske)}
                            className="h-7 px-2 text-xs"
                        >
                            {laufend === kennungVon(offeneMaske) ? texte.laeuft : offeneMaske.label}
                        </Button>

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setMaske(null)}
                            className="h-7 px-2 text-xs"
                        >
                            {texte.abbrechen}
                        </Button>
                    </div>
                </div>
            )}

            {/*
                Die Rueckfrage eines Verbs OHNE Felder — als Kaestchen, nicht
                als `globalThis.confirm`. Der Browser-Dialog haelt den ganzen
                Reiter an, laesst sich nicht uebersetzen und nicht pruefen;
                vor allem ist er genau das, was dieser Task abbaut: eine
                Frage, die nicht in der Leiste steht.
            */}
            {offeneRueckfrage && (
                <div
                    data-slot="mail-werkzeugleiste-rueckfrage"
                    className="flex flex-col gap-2 rounded-md border border-amber-500/40 bg-amber-500/5 p-2"
                >
                    <p className="text-xs">{offeneRueckfrage.confirm}</p>

                    <div className="flex items-center gap-1.5">
                        <Button
                            type="button"
                            size="sm"
                            disabled={laufend === kennungVon(offeneRueckfrage)}
                            onClick={() => void klick(offeneRueckfrage)}
                            className="h-7 px-2 text-xs"
                        >
                            {laufend === kennungVon(offeneRueckfrage) ? texte.laeuft : texte.rueckfrageJa}
                        </Button>

                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setRueckfrage(null)}
                            className="h-7 px-2 text-xs"
                        >
                            {texte.abbrechen}
                        </Button>
                    </div>
                </div>
            )}

            {/*
             * Zwei getrennte Zeilen, weil es zwei Lagen sind:
             *
             *   unerreichbar — das System hat nicht geantwortet (abwarten)
             *   gescheitert  — es hat geantwortet und konnte nicht (nachsehen)
             *
             * Beides als eine Warnung zu zeigen hiesse, jemanden abwarten zu
             * lassen, wo etwas kaputt ist.
             */}
            {unerreichbar.length > 0 && (
                <p className="flex items-start gap-1 text-xs text-amber-700 dark:text-amber-500">
                    <TriangleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {texte.unerreichbar(unerreichbar)}
                </p>
            )}

            {/*
             * Die dritte Lage, und sie ist von den beiden anderen zu
             * unterscheiden: Das System hat geantwortet, das Verb ist in
             * Ordnung — nur DIESE Ansicht kann seine Angabe nicht erfassen.
             * Abwarten hilft nicht, nachsehen auch nicht; es ist eine fehlende
             * Oberfläche, und das gehört gesagt statt verschwiegen.
             */}
            {nichtZeichenbar.length > 0 && (
                <p className="flex items-start gap-1 text-xs text-muted-foreground">
                    <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {texte.nichtZeichenbar(nichtZeichenbar.map((w) => w.label))}
                </p>
            )}

            {gescheitert.length > 0 && (
                <p className="flex items-start gap-1 text-xs text-destructive">
                    <CircleAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {texte.gescheitert(gescheitert)}
                </p>
            )}
            </div>
        </div>
    );
}

/**
 * Die Anhänge DIESER Mail zur Wahl.
 *
 * Die Liste kommt von der Fläche, nicht vom Verb — das Paket kennt die
 * Anhänge schon, es zeigt sie an. Ein Verb, das sie selbst einsammeln müsste,
 * müsste die Mail holen; ein fremdes Verb kann das gar nicht.
 *
 * ## Warum der Dateiname und nicht der Index
 *
 * Der Index stimmt nur, solange beide Seiten dieselbe Mail in derselben
 * Reihenfolge sehen. Bei einer Mail, die ein anderes System geholt hat, ist
 * das eine Annahme und keine Tatsache — und wenn sie nicht stimmt, wird der
 * falsche Anhang verbucht, ohne dass es jemand merkt.
 *
 * ## Mehrfach oder einzeln
 *
 * Zwei Darstellungen, weil es zwei Fragen sind: „welche hängst du an" ist
 * eine Liste mit Haken, „aus welchem machst du den Beleg" ist eine Wahl. Eine
 * Mehrfachauswahl für eine Einzelfrage zu nehmen lädt dazu ein, zwei zu
 * haken, und dann entscheidet drüben die Reihenfolge.
 */
function Anhangwahl({
    feld,
    anhaenge,
    gewaehlt,
    einzeln,
    texte,
    onWahl,
}: {
    feld: MailWerkzeugFeld;
    anhaenge: WerkzeugAnhang[];
    gewaehlt: string[];
    einzeln: string;
    texte: MailWerkzeugleisteLabels;
    onWahl: (wert: string[] | string) => void;
}) {
    if (anhaenge.length === 0) {
        /*
         * Gesagt und nicht verschwiegen. Ein leeres Kästchen sähe aus wie
         * „lädt noch"; der Knopf ist ohnehin aus, wenn das Feld Pflicht ist.
         */
        return <span className="text-muted-foreground">{texte.keineAnhaenge}</span>;
    }

    if (!feld.multiple) {
        return (
            <select
                value={einzeln}
                onChange={(e) => onWahl(e.target.value)}
                className="h-8 rounded-md border bg-background px-1.5 text-xs"
            >
                <option value="">{texte.bitteWaehlen}</option>
                {anhaenge.map((anhang) => (
                    <option key={anhang.filename} value={anhang.filename}>
                        {anhang.filename}
                        {typeof anhang.size === 'number' ? ` (${texte.groesse(anhang.size)})` : ''}
                    </option>
                ))}
            </select>
        );
    }

    return (
        <span className="flex flex-col gap-1">
            {anhaenge.map((anhang) => (
                <label key={anhang.filename} className="flex min-h-8 items-center gap-1.5">
                    <input
                        type="checkbox"
                        checked={gewaehlt.includes(anhang.filename)}
                        onChange={(e) =>
                            onWahl(
                                e.target.checked
                                    ? [...gewaehlt, anhang.filename]
                                    : gewaehlt.filter((name) => name !== anhang.filename),
                            )
                        }
                        className="h-4 w-4"
                    />
                    <Paperclip className="h-3.5 w-3.5 shrink-0 opacity-60" />
                    <span className="truncate">{anhang.filename}</span>
                    {typeof anhang.size === 'number' && (
                        <span className="shrink-0 text-muted-foreground">({texte.groesse(anhang.size)})</span>
                    )}
                </label>
            ))}
        </span>
    );
}
