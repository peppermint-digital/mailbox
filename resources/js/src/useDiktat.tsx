import { useCallback, useEffect, useRef, useState } from 'react';
import type { Editor } from '@tiptap/react';

/**
 * Spracheingabe am Editor — aufnehmen, abtippen lassen, an der Cursorstelle
 * einsetzen.
 *
 * Wie beim Schreibassistenten lag das bis zum 31.10.2026 nur im
 * Projekt-Manager. Es gehoert in jedes System.
 *
 * ## Die Wahrheit steht in Refs, nicht im Zustand
 *
 * Das ist die Eigenheit, an der eine Nachbildung scheitert, und sie ist teuer
 * gelernt: Die harte Obergrenze haengt an einem `setTimeout`, dessen Funktion
 * beim Start festgezurrt wird. Ein Zustandswert darin bliebe fuer immer der
 * vom Startzeitpunkt — also `nimmtAuf === false` —, und die Zwangsabschaltung
 * nach zwei Minuten griffe NIE. Der Zustand existiert zum Zeichnen, die Refs
 * sind die Wahrheit.
 *
 * ## Nichts wird abgelegt
 *
 * Der Datenblock lebt im Speicher bis zum Hochladen und wird danach
 * vergessen. Was gesprochen wurde, gehoert dem Menschen, der es gesprochen
 * hat; eine Aufnahme, die „nur fuer den Fall" liegen bleibt, ist ein
 * Mitschnitt und war nie gemeint.
 *
 * ## Es braucht mehr als Code
 *
 * `MediaRecorder` laeuft nur ueber HTTPS oder auf `localhost`, und es fragt
 * nach dem Mikrofon. Lehnt jemand ab, ist das kein Fehler — es ist eine
 * Antwort, und sie wird als Meldung gezeigt, nicht als Absturz.
 */

export interface DiktatQuelle {
    /** Aufnahme hin, Text zurueck. Wirft bei einem Fehlschlag. */
    abtippen(aufnahme: Blob): Promise<string>;
}

export interface DiktatHaken {
    nimmtAuf: boolean;
    tippt: boolean;
    fehler: string | null;
    umschalten: () => Promise<void>;
}

/** Eine Quelle ueber den Endpunkt des Pakets. */
export function diktatQuelle(basis: string, csrfToken: () => string): DiktatQuelle {
    return {
        async abtippen(aufnahme) {
            const koffer = new FormData();
            koffer.append('audio', aufnahme, 'aufnahme.webm');

            const antwort = await fetch(`${basis}/editor/diktat`, {
                method: 'POST',
                // Kein Content-Type: Den setzt der Browser samt boundary selbst.
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                credentials: 'same-origin',
                body: koffer,
            });

            const inhalt = (await antwort.json().catch(() => ({}))) as { text?: string; message?: string };

            if (!antwort.ok || typeof inhalt.text !== 'string') {
                throw new Error(inhalt.message ?? 'Die Spracheingabe antwortet nicht.');
            }

            return inhalt.text;
        },
    };
}

/** Der erste Audio-Typ, den dieser Browser aufnehmen kann. */
function tonformat(): string {
    const kandidaten = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];

    for (const typ of kandidaten) {
        if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(typ)) {
            return typ;
        }
    }

    return '';
}

/** Zwei Minuten. Wer laenger spricht, diktiert keine Mail mehr. */
const OBERGRENZE_MS = 120_000;

export function useDiktat(editor: Editor | null, quelle: DiktatQuelle | null | undefined): DiktatHaken {
    const [nimmtAuf, setNimmtAuf] = useState(false);
    const [tippt, setTippt] = useState(false);
    const [fehler, setFehler] = useState<string | null>(null);

    const recorder = useRef<MediaRecorder | null>(null);
    const stuecke = useRef<Blob[]>([]);
    const uhr = useRef<ReturnType<typeof setTimeout> | null>(null);
    const nimmtAufRef = useRef(false);

    /* Beim Verlassen der Seite Mikrofon freigeben — sonst bleibt die
       Aufnahmeanzeige des Browsers stehen, obwohl niemand mehr zuhoert. */
    useEffect(
        () => () => {
            recorder.current?.stream.getTracks().forEach((spur) => spur.stop());
            if (uhr.current) {
                clearTimeout(uhr.current);
            }
        },
        [],
    );

    const beenden = useCallback(() => {
        if (uhr.current) {
            clearTimeout(uhr.current);
            uhr.current = null;
        }

        recorder.current?.state === 'recording' && recorder.current.stop();
    }, []);

    const umschalten = useCallback(async () => {
        if (!quelle || tippt) {
            return;
        }

        if (nimmtAufRef.current) {
            beenden();

            return;
        }

        setFehler(null);

        let spur: MediaStream;

        try {
            spur = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch {
            // Abgelehnt oder kein Mikrofon. Beides ist eine Antwort, kein Fehler.
            setFehler('Kein Zugriff auf das Mikrofon.');

            return;
        }

        const format = tonformat();
        const geraet = new MediaRecorder(spur, format === '' ? undefined : { mimeType: format });

        stuecke.current = [];
        recorder.current = geraet;

        geraet.ondataavailable = (ereignis) => {
            if (ereignis.data.size > 0) {
                stuecke.current.push(ereignis.data);
            }
        };

        geraet.onstop = async () => {
            nimmtAufRef.current = false;
            setNimmtAuf(false);
            spur.getTracks().forEach((s) => s.stop());

            const aufnahme = new Blob(stuecke.current, { type: format || 'audio/webm' });
            stuecke.current = [];

            if (aufnahme.size === 0) {
                return;
            }

            setTippt(true);

            try {
                const text = await quelle.abtippen(aufnahme);

                if (text.trim() !== '') {
                    editor?.chain().focus().insertContent(text).run();
                }
            } catch (e) {
                setFehler(e instanceof Error ? e.message : 'Die Spracheingabe antwortet nicht.');
            } finally {
                setTippt(false);
            }
        };

        geraet.start();
        nimmtAufRef.current = true;
        setNimmtAuf(true);

        /*
         * Die Zwangsabschaltung. `beenden` liest den Recorder aus einem Ref —
         * stuende hier ein Zustandswert, waere er fuer immer der vom
         * Startzeitpunkt und die Grenze wirkungslos.
         */
        uhr.current = setTimeout(beenden, OBERGRENZE_MS);
    }, [quelle, tippt, editor, beenden]);

    return { nimmtAuf, tippt, fehler, umschalten };
}

export default useDiktat;
