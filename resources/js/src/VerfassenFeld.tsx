import {
    EmailComposerForm,
    type EmailComposerFormLabels,
    type EmailComposerFormProps,
    type EmailSignatureOption,
    type RecipientSource,
    type RecipientSuggestion,
    type SignatureSource,
} from './EmailComposerForm';
import { useMemo } from 'react';
import { germanLabels } from './germanLabels';
import type { DiktatQuelle } from './useDiktat';
import type { SchreibhilfeQuelle } from './useSchreibhilfe';
import { RichTextEditor } from './RichTextEditor';

/**
 * Das Verfassen-Formular, verdrahtet und auf Deutsch (#8377).
 *
 * ## Warum das hier liegt und nicht im Produkt
 *
 * Es lag dreimal im Bestand — CRM, Verwaltung und AI Brain, je rund 800 Token,
 * auf Token-Ebene zu **99,9 %** identisch. Der ganze Unterschied waren
 * Umbrüche, Kommentare und EIN Wort (siehe `labels` unten).
 *
 * Und es importierte nichts aus dem Produkt: nur `EmailComposerForm` und
 * `RichTextEditor`, beide aus diesem Paket. Eine dreifach gepflegte Datei, die
 * ausschließlich Paketteile verdrahtet, ist ein Teil dieses Pakets.
 *
 * ## Bilder im Text führt das Postfach nicht
 *
 * Ohne `onImageUpload` zeigt der Editor den Knopf gar nicht erst, statt einen
 * anzubieten, der nichts tut.
 */

async function hole(url: string, signal: AbortSignal): Promise<RecipientSuggestion[]> {
    const antwort = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        signal,
    });

    return antwort.ok ? ((await antwort.json()).recipients ?? []) : [];
}

/**
 * Dieselben Quellen wie im Adressbuch — der eine Vorrat kommt einmal, der
 * andere beim Tippen. Als Konstante und nicht als Hook: Sie hängen an nichts,
 * und ein bei jedem Zeichnen neu gebautes Objekt stellt dem Formular seine
 * Verzögerung zurück.
 */
/**
 * Dieselbe Quelle, aber unter dem Routen-Vorsatz eines Produkts (#9116).
 *
 * Der Projekt-Manager hat sein Adressbuch unter `/emails/address-book`, nicht
 * unter `/mailbox/address-book`. Eine feste Adresse hier hiesse: Entweder der
 * Manager kann dieses Feld nicht benutzen, oder er baut es nach — und
 * Nachbauten laufen auseinander.
 *
 * ## Warum gemerkt und nicht bei jedem Aufruf neu
 *
 * Aus demselben Grund, aus dem {@link empfaengerQuelle} eine Konstante ist und
 * kein Hook: Ein bei jedem Zeichnen neu gebautes Objekt stellt dem Formular
 * seine Verzoegerung zurueck, und dann sucht es bei jedem Tastendruck von
 * vorn. Gleicher Vorsatz, gleiches Objekt.
 *
 * Die Merk-Tabelle steht VOR der Fabrik und die Fabrik vor {@link empfaengerQuelle}
 * — nicht aus Stilgefuehl: `empfaengerQuelle` ruft die Fabrik beim Laden des
 * Moduls, und eine danach deklarierte `const` waere dann noch nicht da. Die
 * Typpruefung sieht das nicht, es faellt erst beim ersten Import auf.
 */
const gemerkteQuellen = new Map<string, RecipientSource>();

export function empfaengerQuelleFuer(basis: string): RecipientSource {
    const vorhanden = gemerkteQuellen.get(basis);

    if (vorhanden) {
        return vorhanden;
    }

    const quelle: RecipientSource = {
        all: (signal: AbortSignal) => hole(`${basis}/address-book/colleagues`, signal),
        search: (frage: string, signal: AbortSignal) => hole(`${basis}/address-book/search?q=${encodeURIComponent(frage)}`, signal),
    };

    gemerkteQuellen.set(basis, quelle);

    return quelle;
}

export const empfaengerQuelle = empfaengerQuelleFuer('/mailbox');

/**
 * Eine Quelle, die nichts hat.
 *
 * Sie antwortet leer, statt dass das Formular auf etwas wartet, das nie kommt.
 * Sie ist der Rueckfall fuer ein Produkt, das ausdruecklich keine Signaturen
 * fuehrt — und bis zum 31.10.2026 war sie der Zustand von dreien, weil es
 * keine andere gab.
 *
 * Wer eine Route hat, nimmt {@link signaturQuelleFuer}.
 */
export const leereSignaturQuelle: SignatureSource = {
    signatures: [],
    load: async () => undefined,
    defaultSignature: () => null,
};

/**
 * Die Signaturen vom Server — die Vorgabe seit v0.199.0.
 *
 * Gegenstueck zu {@link empfaengerQuelleFuer}, und aus demselben Grund mit
 * einem Routen-Vorsatz: Der Projekt-Manager liegt unter `/emails`, die anderen
 * unter `/mailbox`. Sie fragt
 *
 *     GET {basis}/{kontoId}/signatures   -> { signatures: [...], available: bool }
 *
 * was {@see \Peppermint\Mailbox\Http\LiefertSignaturen} beantwortet.
 *
 * ## Warum das Objekt seinen Inhalt AENDERT und nicht neu entsteht
 *
 * `SignatureSource` ist eine Eigenschaft (`signatures`) plus zwei Methoden,
 * und das Formular liest die Eigenschaft bei jedem Zeichnen neu. Also fuellt
 * `load()` sie — ein frisches Objekt zurueckzugeben haette keine Wirkung, denn
 * das Formular haelt das alte.
 *
 * Dass danach gezeichnet wird, besorgt das Formular selbst: Es setzt nach
 * `load()` einen Zustand (`geladenFuer`), und der naechste Durchlauf liest die
 * gefuellte Liste. Hier einen eigenen Melder einzubauen waere ein zweiter Weg
 * fuer dasselbe.
 *
 * ## Warum gemerkt
 *
 * Dieselbe Falle wie beim Adressbuch, nur schlimmer: `load` steht in der
 * Abhaengigkeitsliste eines Effekts im Formular. Ein bei jedem Zeichnen neu
 * gebautes Objekt hiesse eine neue Funktion, hiesse der Effekt laeuft erneut,
 * hiesse `load()` bei jedem Tastendruck — und beim Beantworten setzt es den
 * Zustand, der das naechste Zeichnen ausloest. Das ist keine Verzoegerung, das
 * ist eine Schleife.
 */
const gemerkteSignaturQuellen = new Map<string, SignatureSource>();

export function signaturQuelleFuer(basis: string): SignatureSource {
    const vorhanden = gemerkteSignaturQuellen.get(basis);

    if (vorhanden) {
        return vorhanden;
    }

    const quelle: SignatureSource = {
        signatures: [],

        async load(kontoId?: number) {
            /*
             * Ohne Postfach gibt es nichts zu fragen. Das Formular ruft
             * `load(undefined)`, solange keines gewaehlt ist — eine Anfrage an
             * `…/undefined/signatures` waere eine 404, die wie „keine
             * Signaturen" aussieht.
             */
            if (kontoId === undefined || kontoId === null) {
                quelle.signatures = [];

                return;
            }

            try {
                const antwort = await fetch(`${basis}/${kontoId}/signatures`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                /*
                 * Ein Fehlschlag laesst die Liste LEER und nimmt die
                 * Verfassen-Seite nicht mit: Ohne Signatur kann man eine Mail
                 * schreiben, ohne Formular nicht.
                 */
                quelle.signatures = antwort.ok ? ((await antwort.json()).signatures ?? []) : [];
            } catch {
                quelle.signatures = [];
            }
        },

        /*
         * Die Vorgabe des Produkts, und die ERSTE davon. Mehr als eine ist
         * kein Fehler des Produkts, aber eine Wahl, die hier niemand treffen
         * kann — sich auf die Reihenfolge zu verlassen ist ehrlicher als zu
         * raten.
         */
        defaultSignature: () => quelle.signatures.find((eintrag: EmailSignatureOption) => eintrag.is_default) ?? null,
    };

    gemerkteSignaturQuellen.set(basis, quelle);

    return quelle;
}

/** Die Signaturen unter dem Vorsatz, den die meisten Produkte benutzen. */
export const signaturQuelle = signaturQuelleFuer('/mailbox');

/**
 * Der Vorgabe-Editor — gebaut mit den Diensten, die die Hülle bekommen hat.
 *
 * `EmailComposerForm` reicht seinem Editor nur `value` und `onChange` durch;
 * alles andere muss hier hinein. Bis zum 31.10.2026 war das nichts weiter —
 * seit `schreibhilfe` und `diktat` ist es der Unterschied zwischen „eine Zeile
 * je Produkt" und „jedes Produkt baut sich seinen Editor selbst zusammen".
 *
 * Gemeldet von `mail-rollout3` beim Nachziehen: Die Eigenschaften kamen an
 * `VerfassenFeld` an und endeten dort, weil sie an `EmailComposerForm`
 * weitergereicht wurden — und der kennt sie nicht, es sind Eigenschaften des
 * EDITORS. Still: kein Fehler, kein Übersetzerfehler, nur kein Knopf.
 */
function editorMit(dienste: {
    schreibhilfe?: SchreibhilfeQuelle | null;
    schreibhilfeTabu?: string[];
    diktat?: DiktatQuelle | null;
}) {
    return function Editor(eigenschaften: { value: string; onChange: (wert: string) => void }) {
        return <RichTextEditor {...eigenschaften} {...dienste} labels={germanLabels.editor} />;
    };
}

/**
 * Die Dienste und Quellen, die ein Produkt an ein Verfassen-Feld hängt.
 *
 * ## Warum das ein eigener Typ ist (01.11.2026)
 *
 * `VerfassenFeld` nimmt sie seit dem 31.10.2026 — aber nur die Verfassen-SEITE
 * verdrahtet es selbst. Antwort und Weiterleitung bekommen ihr Formular von
 * {@link InlineAntwort} bzw. {@link WeiterleitenFeld}, und die beiden Hüllen
 * setzten `composer={VerfassenFeld}` ohne jede Möglichkeit, etwas
 * durchzureichen.
 *
 * Die Folge, gemessen am 01.11.2026 in Produktion: In AI Brain, CRM und
 * Verwaltung hat der Editor beim Antworten **20 Knöpfe** — Schreibassistent
 * und Spracheingabe fehlen. Auf der Verfassen-Seite derselben Systeme sind
 * sie da. Im Projekt-Manager sind sie auch in der Antwort da, und zwar nur
 * deshalb, weil er die Hülle des Pakets umgeht und sein eigenes Formular
 * einhängt — also genau der Sonderweg, den dieses Paket abschafft.
 *
 * Ein Platz, den die eine Hülle hat und die zwei anderen nicht, ist keine
 * Entscheidung, sondern ein vergessener Durchgang.
 *
 * Es gibt hier bewusst KEINE Vorgaben: Was ein Produkt nicht mitgibt, zeichnet
 * der Editor nicht — ein Knopf ohne Dienst dahinter ist schlimmer als keiner.
 */
export interface VerfasserDienste {
    /** Der Schreibassistent. Fehlt er, gibt es den Knopf nicht. */
    schreibhilfe?: SchreibhilfeQuelle | null;
    /** Knotennamen, die der Assistent nicht anfassen darf. */
    schreibhilfeTabu?: string[];
    /** Die Spracheingabe. Fehlt sie, gibt es den Knopf nicht. */
    diktat?: DiktatQuelle | null;
    /**
     * Die Signaturen. Ohne Angabe hat das Formular keine und zeichnet die
     * Zeile gar nicht — der Zustand von drei Produkten, bis die Signaturen
     * umgezogen sind.
     */
    signatureSource?: SignatureSource;
    /** Der Adressvorrat, falls das Produkt mehr kennt als das Adressbuch. */
    recipients?: RecipientSource;
}

export type VerfassenFeldProps = Omit<EmailComposerFormProps, 'labels' | 'editor' | 'recipients' | 'signatureSource'> &
    /*
     * Adressquelle und Signaturen sind OPTIONAL, nicht weggenommen: Die Vorgabe
     * deckt alle vier Produkte, aber ein Produkt mit eigenem Adressvorrat soll
     * sie ersetzen können, ohne diese Hülle zu umgehen.
     *
     * Dass beide hier noch einmal auftauchen müssen, hat einen Grund: Ein
     * `Omit<…> & Partial<Pick<…>>` ohne das Omit macht sie NICHT optional — der
     * erste Teil verlangt sie weiter. Genau daran fiel der CRM-Übersetzer
     * (TS2739, 22.10.2026).
     */
    Partial<Pick<EmailComposerFormProps, 'recipients' | 'signatureSource' | 'editor'>> & {
        /**
         * Einzelne Wörter überschreiben.
         *
         * Heute braucht es das kein Produkt — nachgemessen: Nur das CRM gibt
         * `source: 'crm'` überhaupt aus, in Verwaltung und Brain war ihr
         * abweichendes Wort toter Code. Die Möglichkeit bleibt trotzdem: Ein
         * Paket, das Sätze ausliefert, liefert eine Sprache aus, und die muss
         * ein Produkt ändern können, ohne die Hülle zu umgehen.
         */
        labels?: Partial<EmailComposerFormLabels>;
        /**
         * Der Schreibassistent. Fehlt er, gibt es den Knopf nicht.
         *
         * Steht hier und nicht nur am Editor, damit ein Produkt EINE Zeile
         * schreibt statt sich den Editor selbst zusammenzubauen. Wer einen
         * eigenen Editor mitgibt, bekommt die Dienste nicht untergeschoben —
         * dann gehören sie zu seinem Editor, nicht zu dieser Hülle.
         */
        schreibhilfe?: SchreibhilfeQuelle | null;
        /** Knotennamen, die der Assistent nicht anfassen darf. */
        schreibhilfeTabu?: string[];
        /** Die Spracheingabe. Fehlt sie, gibt es den Knopf nicht. */
        diktat?: DiktatQuelle | null;
    };

export function VerfassenFeld({
    labels,
    recipients,
    signatureSource,
    editor,
    schreibhilfe,
    schreibhilfeTabu,
    diktat,
    ...eigenschaften
}: VerfassenFeldProps) {
    /*
     * Gemerkt, nicht bei jedem Zeichnen neu gebaut: Eine neue
     * Editor-Komponente bei jedem Durchlauf wäre für React ein ANDERER Typ —
     * der Editor würde ab- und neu aufgebaut, und mit ihm wäre nach jedem
     * Tastendruck die Schreibmarke weg.
     */
    const vorgabeEditor = useMemo(
        () => editorMit({ schreibhilfe, schreibhilfeTabu, diktat }),
        [schreibhilfe, schreibhilfeTabu, diktat],
    );

    return (
        <EmailComposerForm
            {...eigenschaften}
            labels={{ ...germanLabels.verfassen, ...labels } as EmailComposerFormLabels}
            /*
             * Der Editor ist seit dem 31.10.2026 ebenfalls uebersteuerbar, aus
             * demselben Grund wie Adressquelle und Signaturen: Die Vorgabe
             * deckt alle vier Produkte, aber eines mit eigenen Erweiterungen
             * soll sie ersetzen koennen, OHNE diese Huelle zu umgehen.
             *
             * Der Fall dahinter ist konkret: Der Projekt-Manager haengt an
             * seinen Editor einen Schreibassistenten und eine Spracheingabe.
             * Beide sitzen schon in den dafuer vorgesehenen Plaetzen des
             * Paket-Editors (`extraActions`, `statusBar`) — aber die Huelle
             * daraus ist seine, und ohne diesen Platz muesste er an
             * `VerfassenFeld` vorbei direkt auf `EmailComposerForm` gehen.
             */
            editor={editor ?? vorgabeEditor}
            recipients={recipients ?? empfaengerQuelle}
            signatureSource={signatureSource ?? leereSignaturQuelle}
        />
    );
}

export default VerfassenFeld;
