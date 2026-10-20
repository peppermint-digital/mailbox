import { useCallback, useEffect, useRef } from 'react';

/**
 * Eine Kennung, die STABIL abgefragt wird und trotzdem den aktuellen Wert
 * liefert.
 *
 * ## Der Fehler, den dieser Haken unmöglich machen soll
 *
 * `httpMailboxSources` nimmt `accountId: () => …` als Funktion, damit die
 * Quelle stabil bleiben kann: Eine Quelle, die sich bei jedem Zeichnen
 * erneuert, stellt jeden laufenden Abruf neu. Also steht sie in einem `useMemo`
 * mit leerer Abhängigkeitsliste.
 *
 * Genau dort liegt die Falle. Das naheliegende
 *
 *     accountId: () => konto?.id ?? ''
 *
 * schließt über `konto` aus dem ERSTEN Rendern und hält es für immer fest. Der
 * Kommentar darüber schreibt sich dann fast von selbst — „die Kennung wird bei
 * jedem Aufruf frisch erfragt" — und ist falsch. In drei von vier Produkten
 * stand er so, und `eslint-disable react-hooks/exhaustive-deps` unterdrückte
 * die Warnung, die es gesagt hätte.
 *
 * ## Was es gekostet hat
 *
 * Am 23.09.2026 live aufgetreten: Postfach wechseln, Mail anklicken — die
 * Anfrage ging an das ALTE Konto. Nachgestellt als
 * `/mailbox/8/message/2943` für eine Nachricht aus Konto 11.
 *
 * Und es war still. Die LISTE merkte nichts, weil sie ihre Kennung bei jedem
 * Laden ausdrücklich mitbekommt; nur das Öffnen fragt die Quelle. Also: keine
 * Fehlermeldung, kein Protokolleintrag, die Mail ging einfach nicht auf. Wer
 * das meldet, sagt „manchmal gehen Mails nicht auf" — und damit fängt niemand
 * etwas an.
 *
 * Im Projekt-Manager lag dieselbe Falle bis zum 20.10.2026 scharf und
 * unbenutzt: Dort liest `accountId()` niemand, weil Öffnen und Suchen über
 * eigene Quellen laufen. Sie wäre beim nächsten Umbau zugeschnappt.
 *
 * ## Warum ein Haken und nicht eine Regel im Kopf
 *
 * Weil die Regel „nimm hier eine Referenz" vier Mal richtig angewandt werden
 * müsste und drei Mal richtig angewandt WURDE — der vierte Fall ist der, der
 * irgendwann teuer wird. Ein Haken, der das Richtige tut, braucht keine Regel.
 *
 * ## Wann der Abfrager gelesen werden darf — und wann nicht
 *
 * In einem Handler: immer richtig. Beim ZEICHNEN: einen Durchlauf hinterher.
 *
 * Das ist kein Versehen, sondern der Preis dafür, dass nur der FESTGESCHRIEBENE
 * Wert in den Halter kommt. Würde er beim Zeichnen geschrieben, könnte ein
 * Durchlauf, den React wieder verwirft (StrictMode, nebenläufiges Zeichnen),
 * einen Wert hinterlassen, den es nie gegeben hat — und bei einer Kennung, mit
 * der Adressen gebaut werden, ist ein Wert aus einem verworfenen Durchlauf
 * schlimmer als einer, der eine Runde alt ist.
 *
 * Für den Zweck hier ist das folgenlos: `accountId()` wird gerufen, wenn jemand
 * eine Mail anklickt — lange nach dem Zeichnen. Ein Test hat genau diese Kante
 * gefunden, indem er beim Zeichnen fragte; er fragt jetzt dort, wo auch der
 * echte Aufrufer fragt.
 *
 * Wer den Wert BEIM ZEICHNEN braucht, nimmt den Zustand selbst. Dafür ist
 * dieser Haken nicht da.
 *
 * @example
 * const kennung = useAktuelleKennung(konto);
 * const quellen = useMemo(() => httpMailboxSources({ accountId: kennung, routes }), [kennung]);
 */
export function useAktuelleKennung(wert: { id: number | string } | null | undefined): () => number | string {
    /*
     * Mit dem Anfangswert gefuellt, nicht erst im Effekt — fuer den Fall, dass
     * ein Abruf noch vor dem ersten Effekt kommt.
     *
     * Dieser Fall ist UNBELEGT: Ein Test dafuer ueberlebte die
     * Mutationsprobe, weil jeder Klick in jsdom nach dem ersten Effekt liegt.
     * Er ist deshalb entfernt und die Luecke benannt (siehe Testdatei). Der
     * Anfangswert bleibt, weil er strikt sicherer ist und nichts kostet.
     */
    const halter = useRef<{ id: number | string } | null | undefined>(wert);

    /*
     * Nachgeführt im Effekt, nicht beim Zeichnen.
     *
     * React verbietet das Schreiben einer Referenz während des Renderns, und
     * nötig ist es hier nicht: Gelesen wird erst, wenn jemand etwas anklickt
     * — lange nach dem Zeichnen.
     */
    useEffect(() => {
        halter.current = wert;
    }, [wert]);

    /*
     * `useCallback` mit leerer Liste, und das ist der ganze Zweck: Der
     * Abfrager muss über alle Rendern DIESELBE Funktion sein, sonst wird die
     * Quelle, die ihn hält, doch wieder neu gebaut — und man hätte die Falle
     * nur verschoben.
     */
    return useCallback(() => halter.current?.id ?? '', []);
}
