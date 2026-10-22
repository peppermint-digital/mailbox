import {
    EmailComposerForm,
    type EmailComposerFormLabels,
    type EmailComposerFormProps,
    type RecipientSuggestion,
} from './EmailComposerForm';
import { germanLabels } from './germanLabels';
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
export const empfaengerQuelle = {
    all: (signal: AbortSignal) => hole('/mailbox/address-book/colleagues', signal),
    search: (frage: string, signal: AbortSignal) => hole(`/mailbox/address-book/search?q=${encodeURIComponent(frage)}`, signal),
};

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

function Editor(eigenschaften: { value: string; onChange: (wert: string) => void }) {
    return <RichTextEditor {...eigenschaften} labels={germanLabels.editor} />;
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
    Partial<Pick<EmailComposerFormProps, 'recipients' | 'signatureSource'>> & {
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
    };

export function VerfassenFeld({ labels, recipients, signatureSource, ...eigenschaften }: VerfassenFeldProps) {
    return (
        <EmailComposerForm
            {...eigenschaften}
            labels={{ ...germanLabels.verfassen, ...labels } as EmailComposerFormLabels}
            editor={Editor}
            recipients={recipients ?? empfaengerQuelle}
            signatureSource={signatureSource ?? leereSignaturQuelle}
        />
    );
}

export default VerfassenFeld;
