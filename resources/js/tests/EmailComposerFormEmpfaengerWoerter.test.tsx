import { describe, expect, it } from 'vitest';
import { EmailComposerForm } from '../src/EmailComposerForm';
import { germanLabels } from '../src/germanLabels';
import { click, render, textOf } from './support/render';

/**
 * Die Woerter der drei Empfaengerzeilen kommen aus der Woerterliste (02.11.2026, #9319).
 *
 * ## Der Befund dahinter
 *
 * Das Formular schrieb „An", „CC" und „BCC" an fuenf Stellen FEST in den Code
 * — die zwei Umschaltknoepfe neben „An" und die drei Feldbeschriftungen —
 * und las `labels.to`, `labels.cc`, `labels.bcc` nirgends. Die Schluessel gab
 * es, die Vorgaben waren gefuellt („Kopie", „Blindkopie"), und sie standen
 * nur da.
 *
 * Zwei Dinge auf einmal, und das zweite ist das teurere: Ein Produkt KONNTE
 * dort keine anderen Woerter setzen, obwohl die Schnittstelle genau das
 * zusagt („pass your own object and the package imposes nothing",
 * docs/INTEGRATION.md). Und wer die Vorgabenliste las, hielt „Kopie" fuer den
 * Stand — die Oberflaeche hat das Wort nie gezeigt.
 *
 * ## Warum ein Test und nicht eine Notiz
 *
 * Der Fehler hat keine Symptome. Eine fest verdrahtete Beschriftung sieht
 * richtig aus, solange die Vorgabe dasselbe Wort traegt, und genau so war es
 * hier nach der Reparatur gewollt: `germanLabels.verfassen.cc` heisst jetzt
 * „CC", weil es so heissen soll. Wer die Zeile morgen auf einen Literal
 * zuruecksetzt, aendert sichtbar NICHTS und bricht die Zusage wieder.
 *
 * Deshalb prueft dieser Test mit FREMDEN Woertern. Nur die trennen „die
 * Beschriftung stimmt" von „die Beschriftung kommt aus der Liste".
 */
const GRUNDWERTE = {
    editor: () => null,
    recipients: { all: () => Promise.resolve([]), search: () => Promise.resolve([]) },
    signatureSource: { signatures: [], load: async () => undefined, defaultSignature: () => null },
    to: [],
    onToChange: () => {},
    subject: '',
    onSubjectChange: () => {},
    bodyHtml: '',
    onBodyHtmlChange: () => {},
    onSend: () => {},
    onCancel: () => {},
} as never;

/**
 * Woerter, die mit den Vorgaben kein Zeichen teilen.
 *
 * Absichtlich nicht „Kopie"/„Blindkopie": Das waren die alten Vorgaben, und
 * ein Test, der die Vorgabe von vorgestern einsetzt, kann nicht zeigen, dass
 * er die LISTE liest und nicht einen zweiten Literal.
 */
const EIGENE = {
    ...germanLabels.verfassen,
    to: 'Empfaenger',
    cc: 'Mitleser',
    bcc: 'Stille Mitleser',
};

/** Die Beschriftungen der Felder — das, was ueber den Eingabezeilen steht. */
function beschriftungen(container: HTMLElement): string[] {
    return [...container.querySelectorAll('label')].map((l) => (l.textContent ?? '').trim());
}

/** Einen Knopf an seinem Text finden. */
function knopf(container: HTMLElement, text: string): Element {
    const treffer = [...container.querySelectorAll('button')].filter((b) => (b.textContent ?? '').trim() === text);

    expect(treffer, `Knopf „${text}"`).toHaveLength(1);

    return treffer[0];
}

describe('die Woerter der Empfaengerzeilen', () => {
    it('stehen mit den Vorgaben auf „An", „CC" und „BCC" — sichtbar unveraendert', () => {
        /*
         * Die Gegenprobe nach oben: Dieser Test MUSS mit den Vorgaben die
         * Woerter zeigen, die vor dem 02.11.2026 fest im Code standen. Faellt
         * er, hat die Reparatur die Oberflaeche veraendert, und genau das
         * durfte sie nicht.
         */
        const { container, unmount } = render(<EmailComposerForm {...GRUNDWERTE} labels={germanLabels.verfassen} />);

        expect(beschriftungen(container)).toContain('An');

        click(knopf(container, 'CC'));
        click(knopf(container, 'BCC'));

        expect(beschriftungen(container)).toContain('CC');
        expect(beschriftungen(container)).toContain('BCC');

        unmount();
    });

    it('folgen den eigenen Woertern eines Produkts — an den Knoepfen und an den Feldern', () => {
        const { container, unmount } = render(<EmailComposerForm {...GRUNDWERTE} labels={EIGENE} />);

        /* Zeile 1: „An" ist eine Beschriftung, kein Literal. */
        expect(beschriftungen(container)).toContain('Empfaenger');

        /* Die Umschaltknoepfe, bevor die Felder offen sind. */
        const auf = knopf(container, 'Mitleser');
        knopf(container, 'Stille Mitleser');

        click(auf);
        click(knopf(container, 'Stille Mitleser'));

        /* Und die Feldbeschriftungen, nachdem sie aufgegangen sind. */
        const sichtbar = beschriftungen(container);

        expect(sichtbar).toContain('Mitleser');
        expect(sichtbar).toContain('Stille Mitleser');

        unmount();
    });

    it('zeigt „CC" und „BCC" NICHT, wenn das Produkt andere Woerter gesetzt hat', () => {
        /*
         * Der eigentliche Riegel. Die Zusicherungen oben bleiben gruen, wenn
         * jemand `{labels.cc}` wieder durch einen Literal ersetzt UND die
         * Liste dasselbe Wort traegt — denn dann steht das erwartete Wort ja
         * da. Diese Zusicherung nicht: Sie verlangt, dass die alten Literale
         * aus der Oberflaeche VERSCHWINDEN, sobald das Produkt eigene Woerter
         * setzt.
         */
        const { container, unmount } = render(<EmailComposerForm {...GRUNDWERTE} labels={EIGENE} />);

        click(knopf(container, 'Mitleser'));
        click(knopf(container, 'Stille Mitleser'));

        const text = textOf(container);

        expect(text).not.toMatch(/\bCC\b/);
        expect(text).not.toMatch(/\bBCC\b/);
        expect(text).not.toMatch(/\bAn\b/);

        unmount();
    });
});
