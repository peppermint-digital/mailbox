<?php

namespace Peppermint\Mailbox\Contracts;

use Peppermint\Mailbox\Http\LiefertSignaturen;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Woher die Signaturen kommen.
 *
 * ## Warum das eine Schnittstelle ist und kein Dienst
 *
 * Dieselbe Regel wie bei {@see Schreibhilfe} und {@see Zuweisungsspeicher}:
 * Das Paket soll ohne Modell laufen. Eine Signatur ist eine Zeile in einer
 * Tabelle, die es in jedem Produkt anders gibt — im Projekt-Manager haengen
 * sie am Benutzer, woanders koennten sie am Postfach oder an der Firma haengen.
 * Ein `Signature`-Modell hier hiesse: Jedes Produkt bekommt eine Tabelle, die
 * es vielleicht nicht braucht, und das Paket eine Wahrheit, die ihm nicht
 * gehoert.
 *
 * Gefunden von `mail-2b` am 31.10.2026 beim Vergleich der vier
 * Verfassen-Seiten: Eine `signatureSource` gab nur der Projekt-Manager mit; die
 * anderen drei uebergaben `leereSignaturQuelle`, und folglich zeichnete das
 * Formular die Zeile gar nicht. Wieder dasselbe Muster — eine Faehigkeit, die
 * in einem Produkt gewachsen ist und in den anderen drei fehlt, ohne dass es
 * jemand entschieden haette.
 *
 * ## Wer die Person ist, entscheidet das Produkt
 *
 * Hier kommt nur das Postfach an. Signaturen haengen in den Produkten
 * ueberwiegend an der angemeldeten Person, und die kennt das Produkt — es
 * liest sie aus dem Request, dem Zustand, woher auch immer. Sie als Parameter
 * zu fordern hiesse, dass das Paket ein Benutzermodell kennt und entscheidet,
 * WER gemeint ist. Das ist der Fehler, der in diesem Projekt schon zweimal
 * ein fremdes Postfach geoeffnet hat.
 *
 * Das Postfach wird mitgegeben, weil es die Frage mitentscheidet: Wer an
 * `info@` schreibt, soll nicht mit der Signatur seines persoenlichen
 * Postfachs antworten.
 *
 * ## Nichts gebunden heisst „dieses Produkt hat keine"
 *
 * Und das ist eine gueltige Antwort, nicht ein Konfigurationsfehler:
 * {@see LiefertSignaturen} gibt dann eine leere Liste
 * mit `available: false` heraus, und die Oberflaeche zeigt die Zeile nicht.
 */
interface Signaturvorrat
{
    /**
     * Die Signaturen, die an diesem Postfach zur Wahl stehen.
     *
     * `is_default` markiert die vorausgewaehlte. Mehr als eine Vorgabe ist
     * kein Fehler, aber nur die erste wird genommen — raten waere schlimmer,
     * als sich auf die Reihenfolge des Produkts zu verlassen.
     *
     * Eine leere Liste ist eine Antwort: „fuer dieses Postfach keine".
     *
     * @return list<array{id: int|string, name: string, body_html: string, is_default?: bool}>
     */
    public function signaturen(MailAccount $konto): array;
}
