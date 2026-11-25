/**
 * Was der Mailbrowser zuletzt gesehen hat — damit er es beim naechsten Oeffnen
 * sofort zeigen kann (25.11.2026).
 *
 * Bastian: „wenn ich neu in den Mailbrowser gehe, dann laedt wieder. Wenn ich
 * die Seite aktualisiere, laedt wieder." Gemessen: Die erste Seite kommt live
 * per IMAP in 0,4–1,1 s, in Drosselphasen von Office 365 deutlich langsamer,
 * und der Server haelt sie nur zwei Minuten vor. Der Browser merkte sich
 * zwischen zwei Besuchen nichts und zeigte jedes Mal den Kreisel.
 *
 * Jetzt: Beim Oeffnen steht sofort, was zuletzt da war, und die frische
 * Antwort ersetzt es still — so wie Outlook. Den Kreisel gibt es nur noch,
 * wenn noch gar nichts bekannt ist.
 *
 * ## Wo es liegt
 *
 * Im Speicher der Seite (ueberlebt das Wechseln innerhalb der Anwendung) und
 * im `sessionStorage` (ueberlebt das Neuladen im selben Tab, endet mit dem
 * Tab). Gespeichert werden nur Listenzeilen und Ordner — Absender, Betreff,
 * Datum —, nie ein Mailtext. Faellt der `sessionStorage` aus (privates
 * Fenster, voll, gesperrt), bleibt es beim Speicher der Seite; der
 * Mailbrowser funktioniert ohne beides genauso, nur langsamer.
 */

const PRAEFIX = 'peppermint.mailbox.';
const HOECHSTENS = 40;

const speicher = new Map<string, unknown>();

function sitzung(): Storage | null {
    try {
        return typeof window !== 'undefined' ? window.sessionStorage : null;
    } catch {
        return null;
    }
}

export function zuletztGesehen<T>(schluessel: string): T | null {
    if (speicher.has(schluessel)) {
        return speicher.get(schluessel) as T;
    }

    try {
        const roh = sitzung()?.getItem(PRAEFIX + schluessel);

        if (roh) {
            const wert = JSON.parse(roh) as T;
            speicher.set(schluessel, wert);

            return wert;
        }
    } catch {
        // Kaputt oder gesperrt: dann eben nichts Bekanntes.
    }

    return null;
}

export function merken(schluessel: string, wert: unknown): void {
    speicher.delete(schluessel);
    speicher.set(schluessel, wert);

    // Gedeckelt: Wer durch viele Ordner und Seiten blaettert, soll nicht alles
    // davon im Tab behalten. Die aeltesten gehen zuerst.
    while (speicher.size > HOECHSTENS) {
        const aeltester = speicher.keys().next().value as string;
        speicher.delete(aeltester);

        try {
            sitzung()?.removeItem(PRAEFIX + aeltester);
        } catch {
            // egal
        }
    }

    try {
        sitzung()?.setItem(PRAEFIX + schluessel, JSON.stringify(wert));
    } catch {
        // Voll oder gesperrt — der Speicher der Seite reicht dann.
    }
}

/** Nur fuer Tests: alles vergessen. */
export function allesVergessen(): void {
    speicher.clear();

    try {
        const s = sitzung();

        if (!s) {
            return;
        }

        for (const k of Object.keys(s)) {
            if (k.startsWith(PRAEFIX)) {
                s.removeItem(k);
            }
        }
    } catch {
        // egal
    }
}
