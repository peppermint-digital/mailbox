import {
    EmailComposerForm,
    type EmailComposerFormLabels,
    type EmailComposerFormProps,
    type RecipientSource,
    type RecipientSuggestion,
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
 * Keines der drei Systeme führt Signaturen im Postfach.
 *
 * Die Quelle antwortet leer, statt dass das Formular auf etwas wartet, das nie
 * kommt. Wer Signaturen hat, gibt eine eigene Quelle mit.
 */
export const leereSignaturQuelle = {
    signatures: [],
    load: async () => undefined,
    defaultSignature: () => null,
};

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
