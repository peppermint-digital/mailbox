import { useCallback, useEffect, useState } from 'react';

/**
 * Ein Schalter, der einen Neuaufruf ueberlebt — pro Browser.
 *
 * ## Warum das ins Paket gehoert
 *
 * `useMailboxSidePanel` merkte sich seit je, ob der Assistent offen ist. Die
 * Ordnerspalte tat es nicht: Wer sie zuklappte, hatte sie beim naechsten
 * Aufruf wieder vor sich. Der Projekt-Manager loeste das serverseitig ueber
 * die Einstellungen des Benutzers — CRM und Verwaltung hatten gar nichts.
 *
 * Zweimal dasselbe zu schreiben waere die dritte Kopie derselben zehn Zeilen
 * gewesen. Also einmal, hier.
 *
 * ## Zwei Kleinigkeiten, die leicht schiefgehen
 *
 * **Lesen kann werfen.** `localStorage` wirft in manchen Browsern im privaten
 * Fenster nicht etwa `null`, sondern eine Ausnahme. Ein ungeschuetzter Zugriff
 * nimmt die ganze Seite mit — und es braucht einen Browser, in dem niemand
 * testet, um das zu merken.
 *
 * **Erst nach dem ersten Zeichnen lesen.** Der gespeicherte Wert steht beim
 * Serverrendern nicht zur Verfuegung. Wer ihn in `useState` hineinreicht,
 * bekommt beim Abgleich zwei verschiedene Baeume.
 *
 * ## Pro Browser, nicht pro Benutzer
 *
 * Ob eine Spalte eingeklappt ist, ist eine Bequemlichkeit dieses einen
 * Bildschirms. Sie zum Server zu tragen waere ein Schreibvorgang bei jedem
 * Klick fuer eine Angabe, die auf einem anderen Geraet ohnehin nicht passt.
 */
export function useMailboxPreference(key: string, standard: boolean): [boolean, (wert: boolean) => void] {
    const [wert, setWert] = useState(standard);

    useEffect(() => {
        try {
            const gespeichert = localStorage.getItem(key);

            if (gespeichert !== null) {
                setWert(gespeichert === 'true');
            }
        } catch {
            // Privates Fenster oder Speicher gesperrt — dann gilt die Vorgabe.
        }
    }, [key]);

    const setzen = useCallback(
        (neu: boolean) => {
            setWert(neu);

            try {
                localStorage.setItem(key, String(neu));
            } catch {
                // Nicht speicherbar — die Sitzung merkt es sich trotzdem.
            }
        },
        [key],
    );

    return [wert, setzen];
}
