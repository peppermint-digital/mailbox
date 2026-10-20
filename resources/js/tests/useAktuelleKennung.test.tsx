import { describe, expect, it } from 'vitest';
import { useMemo, useState } from 'react';
import { useAktuelleKennung } from '../src/useAktuelleKennung';
import { render } from './support/render';

/**
 * Die Falle, die am 23.09.2026 live zuschnappte (#8316).
 *
 * `accountId: () => konto?.id` in einem `useMemo` mit leerer
 * Abhängigkeitsliste schließt über das ERSTE Postfach und hält es für immer
 * fest. Postfach wechseln, Mail anklicken — die Anfrage ging an das alte
 * Konto, ohne Fehlermeldung.
 *
 * Diese Tests prüfen beide Hälften der Zusage, und sie widersprechen sich
 * scheinbar:
 *
 *   1. Der Abfrager liefert den AKTUELLEN Wert.
 *   2. Der Abfrager ist über alle Rendern DIESELBE Funktion.
 *
 * Nur zusammen sind sie etwas wert. Wer nur (1) hat, baut die Quelle bei jedem
 * Zeichnen neu und stellt jeden laufenden Abruf neu. Wer nur (2) hat, hat die
 * Falle.
 */

/** Eine Hülle, die wechselt und sichtbar macht, was der Abfrager sagt. */
function Probe() {
    const [konto, setKonto] = useState<{ id: number } | null>({ id: 11 });
    const kennung = useAktuelleKennung(konto);

    /*
     * Genau der Aufbau aus den Produktseiten: Die „Quelle" steht in einem
     * `useMemo`, das vom Abfrager abhängt. Ist der stabil, wird sie EINMAL
     * gebaut — und muss trotzdem den neuen Wert sehen.
     */
    const quelle = useMemo(() => ({ frage: () => kennung() }), [kennung]);

    /*
     * Gefragt wird in einem HANDLER, nicht beim Zeichnen.
     *
     * Das ist nicht bequem gewaehlt, sondern der echte Ort: `accountId()` wird
     * gerufen, wenn jemand eine Mail anklickt. Der erste Versuch dieses Tests
     * fragte beim Zeichnen und fiel — zu Recht, denn dort ist der Abfrager
     * einen Durchlauf hinterher (der Effekt laeuft nach dem Zeichnen). Die
     * Kante ist am Haken dokumentiert.
     */
    const [gesehen, setGesehen] = useState('—');

    return (
        <div>
            <span data-slot="gesehen">{gesehen}</span>
            <span data-slot="bauten">{String(gebaut(quelle))}</span>
            <button data-slot="fragen" onClick={() => setGesehen(String(quelle.frage()))}>
                fragen
            </button>
            <button data-slot="wechseln" onClick={() => setKonto({ id: 8 })}>
                wechseln
            </button>
            <button data-slot="leeren" onClick={() => setKonto(null)}>
                leeren
            </button>
        </div>
    );
}

/** Klickt und laesst React fertig werden. */
async function klick(container: HTMLElement, slot: string): Promise<void> {
    container.querySelector<HTMLButtonElement>(`[data-slot="${slot}"]`)!.click();
    await new Promise((r) => setTimeout(r, 0));
}

/** Zählt, wie oft eine neue Quelle entstanden ist — über ihre Identität. */
const gesehen = new WeakMap<object, number>();
let zaehler = 0;

function gebaut(quelle: object): number {
    if (!gesehen.has(quelle)) {
        gesehen.set(quelle, ++zaehler);
    }

    return gesehen.get(quelle)!;
}

describe('useAktuelleKennung', () => {
    it('liefert nach einem Wechsel die NEUE Kennung', async () => {
        // Der Kern. Ohne das ging die Mail aus Konto 11 an Konto 8.
        const { container, unmount } = render(<Probe />);

        await klick(container, 'fragen');
        expect(container.querySelector('[data-slot="gesehen"]')!.textContent).toBe('11');

        await klick(container, 'wechseln');
        await klick(container, 'fragen');

        expect(container.querySelector('[data-slot="gesehen"]')!.textContent).toBe('8');
        unmount();
    });

    it('baut die Quelle dabei NICHT neu', async () => {
        /*
         * Die andere Hälfte, und sie ist der Grund für die ganze Konstruktion:
         * Eine Quelle, die sich erneuert, stellt jeden laufenden Abruf neu.
         * Deshalb muss der Abfrager dieselbe Funktion bleiben.
         */
        const { container, unmount } = render(<Probe />);

        const vorher = container.querySelector('[data-slot="bauten"]')!.textContent;

        await klick(container, 'wechseln');

        expect(container.querySelector('[data-slot="bauten"]')!.textContent).toBe(vorher);
        unmount();
    });

    it('antwortet mit einer leeren Zeichenkette, wenn es kein Postfach gibt', async () => {
        /*
         * Nicht `undefined` und nicht `0`: Die Kennung wird in eine Adresse
         * eingesetzt. Eine `0` ergäbe `/mailbox/0/message/…` — eine Anfrage an
         * ein Postfach, das es nicht gibt, mit einer Antwort, die wie eine
         * Absage aussieht. Eine leere Zeichenkette ergibt eine offensichtlich
         * kaputte Adresse, und das ist besser als eine plausibel falsche.
         */
        const { container, unmount } = render(<Probe />);

        await klick(container, 'leeren');
        await klick(container, 'fragen');

        expect(container.querySelector('[data-slot="gesehen"]')!.textContent).toBe('');
        unmount();
    });

    /*
     * HIER STAND EIN TEST, der behauptete, den Anfangswert des Halters zu
     * pruefen („kennt den ersten Wert schon beim allerersten Abruf").
     *
     * Er hat die Mutationsprobe ueberlebt: Mit `useRef(undefined)` statt
     * `useRef(wert)` blieb er gruen. Der Grund ist der Zeitpunkt — jeder Klick
     * in diesem Test passiert NACH dem ersten Effekt, und da ist der Halter
     * ohnehin gefuellt. Ein Abruf VOR dem ersten Effekt liesse sich hier nicht
     * herstellen.
     *
     * Der Anfangswert bleibt im Haken, weil er strikt sicherer ist und nichts
     * kostet. Aber er bleibt UNBELEGT, und das steht hier, statt dass ein
     * gruener Test etwas anderes behauptet.
     */
});
