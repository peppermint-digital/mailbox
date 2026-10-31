import { useCallback, useState } from 'react';
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
 * const entwurf = useEntwurfVerfassen(draft, { kontoId: accounts[0]?.id ?? null });
 *
 * // … <VerfassenFeld accountId={entwurf.kontoId} onAccountIdChange={entwurf.setKontoId}
 * //                  to={entwurf.an} onToChange={entwurf.setAn} … />
 *
 * fetch(`/mailbox/${entwurf.kontoId}/send`, { body: JSON.stringify({ …, ...entwurf.sendeFelder }) })
 * ```
 *
 * **Die Adresse des Sendens haengt an `entwurf.kontoId`, nicht an
 * `accounts[0]`.** Genau dort sass der Fehler: Ein Entwurf aus dem zweiten
 * Postfach wurde ueber das erste verschickt.
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
    /**
     * Das Postfach, in dem der Entwurf liegt.
     *
     * Reist mit, seit dem 31.10.2026: Die Verfassen-Seiten setzten ihr Konto
     * auf `accounts[0]`. Bei einem Entwurf aus einem ZWEITEN Postfach ging die
     * Nachricht damit ueber das falsche Konto hinaus — und abgeloest wurde der
     * Entwurf auch nicht, weil das Abloesen dasselbe falsche Postfach fragt.
     */
    account_id: number | string;
    folder: string;
    uid: number | string;
    to?: EmailAddress[];
    cc?: EmailAddress[];
    /**
     * Blindkopie — in einem abgelegten Entwurf steht sie NICHT.
     *
     * `BearbeitetEntwuerfe` liefert hier immer `[]`, und das ist richtig: Ein
     * Entwurf im IMAP-Ordner traegt keinen `Bcc`-Kopf, weil der beim Senden
     * entfernt wird. Das Feld steht trotzdem hier und nicht nur im Formular,
     * damit es EINEN Ort fuer den Zustand gibt — siehe unten.
     */
    bcc?: EmailAddress[];
    subject?: string;
    body_html?: string;
    attachments?: { filename: string; size: number }[];
}

export interface EntwurfVerfassen {
    /**
     * Das vorgewaehlte Postfach — das des Entwurfs, sonst die Vorbelegung.
     *
     * Das Produkt reicht es an die Kontoauswahl durch, statt selbst
     * `accounts[0]` zu nehmen.
     */
    kontoId: number | null;
    setKontoId: (wert: number | null) => void;
    an: EmailAddress[];
    setAn: (wert: EmailAddress[]) => void;
    kopie: EmailAddress[];
    setKopie: (wert: EmailAddress[]) => void;
    /**
     * Die Blindkopie.
     *
     * Stand bis zum 31.10.2026 nicht hier, und das war eine Luecke und keine
     * Entscheidung: `BearbeitetEntwuerfe` liefert `bcc` mit,
     * `HandlesSending::send` nimmt es an — nur das Stueck dazwischen fehlte.
     * Gefunden von `mail-2b` beim Vergleich der vier Verfassen-Seiten.
     *
     * Ein Produkt KOENNTE es mit eigenem `useState` halten. Dann liegt der
     * Zustand des Formulars an zwei Stellen, und bei der naechsten Ergaenzung
     * weiss niemand mehr, welche fuehrt — genau das, wogegen der Kommentar
     * oben an dieser Datei argumentiert.
     */
    blindkopie: EmailAddress[];
    setBlindkopie: (wert: EmailAddress[]) => void;
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
    /**
     * Was beim SPEICHERN mitmuss, damit die Vorfassung ersetzt und nicht
     * verdoppelt wird.
     *
     * Dieselbe Fassung wie in {@see sendeFelder}, nur in der Schreibweise, die
     * `SpeichertEntwuerfe` liest. Zwei Namen fuer eine Sache, und absichtlich
     * BEIDE hier: Liesse man das Produkt umbenennen, haette es die Fassung in
     * der Hand — und damit die Gelegenheit, die eine Stelle zu zwei zu machen.
     */
    speicherFelder: { replace_folder?: string; replace_uid?: string };
    /**
     * Nach dem Speichern: Welche Fassung jetzt im Postfach liegt.
     *
     * ## Warum es das geben MUSS
     *
     * Gefunden von `mail-2b` am 31.10.2026 beim Verdrahten von v0.198.0, und
     * sie hat es gemeldet, bevor sie es im Produkt geloest hat.
     *
     * Der Ablauf, der ohne das bricht, ist der normale: Entwurf oeffnen,
     * weiterschreiben, „Als Entwurf speichern" (der Server legt eine neue
     * Fassung ab und raeumt die alte per `purge` weg), dann doch senden.
     *
     * `sendeFelder` war aus dem ANFANGSWERT abgeleitet und aenderte sich nie.
     * Beim Senden gingen also `draft_folder`/`draft_uid` der Fassung mit, die
     * gerade entfernt wurde. Zwei stille Folgen:
     *
     * 1. Die Anhaenge werden aus einem Entwurf nachgeladen, den es nicht mehr
     *    gibt.
     * 2. Abgeloest wird nach dem Senden nichts — die gerade gespeicherte
     *    Fassung bleibt im Entwurfsordner liegen.
     *
     * ## Warum im Haken und nicht im Produkt
     *
     * Dasselbe Argument wie oben an dieser Datei, und `mail-2b` hat es mir
     * zurueckgegeben: Haelt das Produkt die Fassung mit eigenem `useState` und
     * streut sie beim Senden ueber `sendeFelder`, liegt sie an zwei Stellen —
     * und bei der naechsten Ergaenzung weiss niemand, welche fuehrt.
     *
     * ## Keine Kennung heisst: nicht mehr ersetzen
     *
     * `null` oder eine leere Kennung setzt die Fassung ZURUECK, statt die alte
     * stehen zu lassen. Ein Server, der keine nennt (IMAPs `APPEND` darf das),
     * hat die Vorfassung trotzdem entfernt — die alte Kennung zeigt dann ins
     * Leere, und sie weiter mitzuschicken ist genau der Fehler, den dieser
     * Haken behebt. Die Folge ist, dass die gespeicherte Fassung nach dem
     * Senden liegen bleibt; das ist sichtbar und harmlos, waehrend das andere
     * still und schaedlich ist.
     */
    fassungGesetzt: (ordner?: string | null, uid?: number | string | null) => void;
}

export function useEntwurfVerfassen(
    entwurf: VerfasstenEntwurf | null | undefined,
    vorbelegung?: { an?: EmailAddress[]; kontoId?: number | null },
): EntwurfVerfassen {
    /*
     * Anfangswerte, nicht laufend abgeglichen: Der Entwurf kommt mit der Seite
     * und aendert sich waehrend des Schreibens nicht. Ein `useEffect`, der ihn
     * nachtraegt, waere genau der Rueckspringer aus dem Kommentar oben.
     */
    const [kontoId, setKontoId] = useState<number | null>(
        entwurf ? Number(entwurf.account_id) : (vorbelegung?.kontoId ?? null),
    );
    const [an, setAn] = useState<EmailAddress[]>(entwurf?.to ?? vorbelegung?.an ?? []);
    const [kopie, setKopie] = useState<EmailAddress[]>(entwurf?.cc ?? []);
    const [blindkopie, setBlindkopie] = useState<EmailAddress[]>(entwurf?.bcc ?? []);
    const [betreff, setBetreff] = useState(entwurf?.subject ?? '');
    const [inhalt, setInhalt] = useState(entwurf?.body_html ?? '');

    /**
     * Welche Fassung dieses Entwurfs gerade im Postfach liegt.
     *
     * Der eine Ort fuer eine Angabe, die sich AENDERT — anders als alles
     * andere in diesem Haken, das Anfangswerte sind. Siehe
     * {@see EntwurfVerfassen.fassungGesetzt}.
     */
    const [fassung, setFassung] = useState<{ ordner: string; uid: string } | null>(
        entwurf && String(entwurf.uid ?? '') !== '' ? { ordner: entwurf.folder, uid: String(entwurf.uid) } : null,
    );

    const fassungGesetzt = useCallback((ordner?: string | null, uid?: number | string | null) => {
        const kennung = uid === null || uid === undefined ? '' : String(uid);

        setFassung(kennung === '' || !ordner ? null : { ordner, uid: kennung });
    }, []);

    return {
        kontoId,
        setKontoId,
        an,
        setAn,
        kopie,
        setKopie,
        blindkopie,
        setBlindkopie,
        betreff,
        setBetreff,
        inhalt,
        setInhalt,
        vorhandeneAnhaenge: entwurf?.attachments ?? [],
        /*
         * Aus der FASSUNG abgeleitet und nicht aus dem Anfangswert. Beides
         * sieht gleich aus, solange niemand speichert — und danach zeigt das
         * eine auf eine Nachricht, die es nicht mehr gibt.
         */
        sendeFelder: fassung ? { draft_folder: fassung.ordner, draft_uid: fassung.uid } : {},
        speicherFelder: fassung ? { replace_folder: fassung.ordner, replace_uid: fassung.uid } : {},
        fassungGesetzt,
    };
}

export default useEntwurfVerfassen;
