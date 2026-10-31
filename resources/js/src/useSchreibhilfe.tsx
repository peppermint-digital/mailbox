import { useCallback, useRef, useState } from 'react';
import type { Editor } from '@tiptap/react';

/**
 * Der Schreibassistent am Editor — korrigieren und umformulieren.
 *
 * ## Warum er jetzt im Paket liegt
 *
 * Bis zum 31.10.2026 hatte ihn nur der Projekt-Manager, in seiner eigenen
 * Editor-Huelle. Die anderen drei hatten keinen — nicht weil es jemand so
 * entschieden haette, sondern weil er dort gewachsen ist. Dieselbe
 * Ungleichheit wie bei den Entwuerfen, und Bastians Regel dazu ist eindeutig:
 * in jedem System die Funktionen aller Systeme.
 *
 * Der Haken haengt an KEINEM Editor-Zustand und zeichnet bei
 * Tiptap-Transaktionen nicht neu: Der Editor wird hereingereicht, nicht
 * abonniert.
 *
 * ## Die Signatur bleibt aussen vor
 *
 * Ein Modell, das „freundlicher" formulieren soll, formuliert sonst die
 * Grussformel um — und das ist nie gemeint.
 *
 * WELCHER Teil die Signatur ist, weiss das Paket allerdings nicht. Im
 * Projekt-Manager ist sie ein eigener Tiptap-Knoten (`signatureBlock`), den
 * der Paket-Editor nicht kennt; anderswo koennten es HTML-Kommentare sein.
 * Deshalb nennt das PRODUKT seine Tabu-Knoten, statt dass das Paket raet.
 * Ohne Angabe wird nichts ausgenommen — dann ist der ganze Rumpf der Text,
 * und das ist fuer einen Verfasser ohne Signaturbegriff richtig.
 */

export type SchreibhilfeModus = 'correct' | 'rephrase';
export type SchreibhilfeStil = 'formal' | 'friendly' | 'shorter';

export interface SchreibhilfeQuelle {
    /**
     * Text hin, Text zurueck. Wirft bei einem Fehlschlag — der Haken macht
     * daraus eine Meldung, keinen Absturz.
     */
    verarbeiten(text: string, modus: SchreibhilfeModus, stil?: SchreibhilfeStil): Promise<string>;
}

export interface SchreibhilfeHaken {
    laeuft: boolean;
    fehler: string | null;
    /** Auf der Auswahl, sonst auf dem ganzen Rumpf ohne Signatur. */
    anwenden: (modus: SchreibhilfeModus, stil?: SchreibhilfeStil) => Promise<void>;
}

/** Eine Quelle ueber den Endpunkt des Pakets. */
export function schreibhilfeQuelle(basis: string, csrfToken: () => string): SchreibhilfeQuelle {
    return {
        async verarbeiten(text, modus, stil) {
            const antwort = await fetch(`${basis}/editor/schreibhilfe`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ text, mode: modus, style: stil ?? null }),
            });

            const inhalt = (await antwort.json().catch(() => ({}))) as { result?: string; message?: string };

            if (!antwort.ok || typeof inhalt.result !== 'string') {
                throw new Error(inhalt.message ?? 'Der Schreibassistent antwortet nicht.');
            }

            return inhalt.result;
        },
    };
}

function textAusAuswahl(editor: Editor): string {
    const { from, to } = editor.state.selection;

    return from === to ? '' : editor.state.doc.textBetween(from, to, '\n');
}

/** Absaetze aus Klartext — mit entschaerften Zeichen, nicht roh eingesetzt. */
function alsAbsaetze(text: string): string {
    const entschaerft = (teil: string) => teil.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const sauber = text.replace(/\r\n/g, '\n').trim();

    if (sauber === '') {
        return '<p><br></p>';
    }

    return sauber
        .split(/\n{2,}/)
        .map((absatz) => `<p>${entschaerft(absatz).replace(/\n/g, '<br>')}</p>`)
        .join('');
}

export function useSchreibhilfe(
    editor: Editor | null,
    quelle: SchreibhilfeQuelle | null | undefined,
    optionen?: {
        /**
         * Knotennamen, die der Assistent nicht anfassen darf — im
         * Projekt-Manager `['signatureBlock']`.
         */
        tabuKnoten?: string[];
    },
): SchreibhilfeHaken {
    const [laeuft, setLaeuft] = useState(false);
    const [fehler, setFehler] = useState<string | null>(null);
    /* Refs, nicht Zustand: Ein zweiter Klick waehrend des Laufs darf nicht
       durchkommen, und der Zustand ist dafuer einen Takt zu spaet. */
    const laeuftRef = useRef(false);

    const anwenden = useCallback(
        async (modus: SchreibhilfeModus, stil?: SchreibhilfeStil) => {
            if (!editor || !quelle || laeuftRef.current) {
                return;
            }

            const tabu = optionen?.tabuKnoten ?? [];
            const auswahl = textAusAuswahl(editor);

            /*
             * Zwei Wege, und sie unterscheiden sich an genau einer Stelle: Bei
             * einer Auswahl weiss der Mensch, was er meint — dann wird nur sie
             * ersetzt. Ohne Auswahl ist der ganze Rumpf gemeint, und dann
             * muessen die Tabu-Knoten vorher heraus und hinterher wieder dran.
             */
            let grenze = editor.state.doc.content.size;

            if (tabu.length > 0) {
                editor.state.doc.descendants((knoten, position) => {
                    if (tabu.includes(knoten.type.name) && position < grenze) {
                        grenze = position;
                    }
                });
            }

            const text = auswahl !== '' ? auswahl : editor.state.doc.textBetween(0, grenze, '\n');

            if (text.trim() === '') {
                return;
            }

            laeuftRef.current = true;
            setLaeuft(true);
            setFehler(null);

            try {
                const neu = await quelle.verarbeiten(text, modus, stil);

                if (auswahl !== '') {
                    const { from, to } = editor.state.selection;
                    editor.chain().focus().insertContentAt({ from, to }, alsAbsaetze(neu)).run();
                } else {
                    /*
                     * Nur bis zur Grenze ersetzen. Die Signatur steht dahinter
                     * und bleibt damit unberuehrt — sie wird nicht
                     * „wieder angehaengt", sie war nie weg.
                     */
                    editor.chain().focus().insertContentAt({ from: 0, to: grenze }, alsAbsaetze(neu)).run();
                }
            } catch (e) {
                setFehler(e instanceof Error ? e.message : 'Der Schreibassistent antwortet nicht.');
            } finally {
                laeuftRef.current = false;
                setLaeuft(false);
            }
        },
        [editor, quelle, optionen?.tabuKnoten],
    );

    return { laeuft, fehler, anwenden };
}

export default useSchreibhilfe;
