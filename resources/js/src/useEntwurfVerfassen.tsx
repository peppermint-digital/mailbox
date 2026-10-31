import { useState } from 'react';
import type { EmailAddress } from './EmailComposerForm';

/**
 * Einen geladenen Entwurf in den Verfasser bringen — in jedem Produkt gleich.
 *
 * ## Warum das ein Haken ist und keine Eigenschaft am Verfasser
 *
 * {@see EmailComposerForm} ist gefuehrt: Die Seite haelt `an`, `betreff`,
 * `inhalt` und schickt selbst ab. Eine Eigenschaft `entwurf` am Formular
 * muesste diesen Zustand uebernehmen — dann gaebe es zwei Stellen, die ihn
 * fuehren, und die bekannte Sorte Fehler, bei der ein Feld nach dem ersten
 * Tippen wieder auf den Entwurf zurueckspringt.
 *
 * Der Haken dreht es um: Er gibt die ANFANGSWERTE heraus und die zwei Felder,
 * die beim Senden mitmuessen. Gefuehrt wird weiter an einer Stelle.
 *
 * ## Was das Produkt damit tut
 *
 * ```tsx
 * const entwurf = useEntwurfVerfassen(draft);   // `draft` kommt vom Controller
 *
 * // … <VerfassenFeld to={entwurf.an} onToChange={entwurf.setAn} … />
 *
 * body: JSON.stringify({ …, ...entwurf.sendeFelder })
 * ```
 *
 * ## Die Anhaenge sind absichtlich keine `File`
 *
 * Sie liegen im Postfach, nicht im Browser — es gibt keine Bytes, die man in
 * ein `File` legen koennte, und es sollen auch keine durch die Seite reisen
 * (Bug #1155). `vorhandeneAnhaenge` ist deshalb eine reine Anzeige: Name und
 * Groesse, damit der Absender SIEHT, was mitgeht. Beim Senden holt sie das
 * Paket serverseitig aus dem Entwurf.
 */

export interface VerfasstenEntwurf {
    folder: string;
    uid: number | string;
    to?: EmailAddress[];
    cc?: EmailAddress[];
    subject?: string;
    body_html?: string;
    attachments?: { filename: string; size: number }[];
}

export interface EntwurfVerfassen {
    an: EmailAddress[];
    setAn: (wert: EmailAddress[]) => void;
    kopie: EmailAddress[];
    setKopie: (wert: EmailAddress[]) => void;
    betreff: string;
    setBetreff: (wert: string) => void;
    inhalt: string;
    setInhalt: (wert: string) => void;
    /** Nur zum Anzeigen — die Dateien liegen im Postfach. */
    vorhandeneAnhaenge: { filename: string; size: number }[];
    /**
     * Was beim Senden mitmuss, damit das Paket die Anhaenge nachlaedt und den
     * Entwurf abloest. Leer, wenn kein Entwurf im Spiel ist — dann kann das
     * Produkt es bedenkenlos hineinstreuen.
     */
    sendeFelder: { draft_folder?: string; draft_uid?: string };
}

export function useEntwurfVerfassen(
    entwurf: VerfasstenEntwurf | null | undefined,
    vorbelegung?: { an?: EmailAddress[] },
): EntwurfVerfassen {
    /*
     * Anfangswerte, nicht laufend abgeglichen: Der Entwurf kommt mit der Seite
     * und aendert sich waehrend des Schreibens nicht. Ein `useEffect`, der ihn
     * nachtraegt, waere genau der Rueckspringer aus dem Kommentar oben.
     */
    const [an, setAn] = useState<EmailAddress[]>(entwurf?.to ?? vorbelegung?.an ?? []);
    const [kopie, setKopie] = useState<EmailAddress[]>(entwurf?.cc ?? []);
    const [betreff, setBetreff] = useState(entwurf?.subject ?? '');
    const [inhalt, setInhalt] = useState(entwurf?.body_html ?? '');

    return {
        an,
        setAn,
        kopie,
        setKopie,
        betreff,
        setBetreff,
        inhalt,
        setInhalt,
        vorhandeneAnhaenge: entwurf?.attachments ?? [],
        sendeFelder: entwurf ? { draft_folder: entwurf.folder, draft_uid: String(entwurf.uid) } : {},
    };
}

export default useEntwurfVerfassen;
