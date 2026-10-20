<?php

namespace Peppermint\Mailbox\Contracts;

use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Zuweisung;
use Peppermint\Mailbox\Zustand\Zuweisungsliste;

/**
 * Wo steht, wer sich um eine Unterhaltung kuemmert.
 *
 * ## Dieselbe Aufteilung wie bei Postfaechern und Verlauf
 *
 * Entschieden am 19.10.2026. Die Probe ist die aus dem verbindlichen Wiki:
 * **Beschreibt es die Sache oder den Umgang des Produkts damit?**
 *
 * „Wer kuemmert sich um diese Unterhaltung" beschreibt die Sache. Es ist, wie
 * die Leseliste, eine Entscheidung ueber Menschen — und die faellt einmal fuer
 * alle Systeme. Vier Tabellen heissen vier Antworten auf dieselbe Frage; wer
 * im CRM zuweist, hat im Manager niemandem etwas zugewiesen.
 *
 * Gemessen am 19.10.2026: `mail_assignments` in AI Brain, CRM und Verwaltung,
 * `email_assignments` im Manager. Dieselbe Mail, vier Zustaendigkeiten.
 *
 * - {@see \Peppermint\Mailbox\Stores\LokalerZuweisungsspeicher} — eigene Tabelle
 * - {@see \Peppermint\Mailbox\Stores\BrainZuweisungsspeicher} — ueber die Bridge
 *
 * ## Die Kette, nicht die Nachricht
 *
 * Jeder Weg hier nimmt Nachricht UND Kette. Gespeichert wird an der Kette, wo
 * es eine gibt; die Nachricht bleibt daneben, weil sie sagt, wo die Zuweisung
 * entstand — und weil sie den Altbestand ohne Kettenkennung auffaengt.
 *
 * ## Fehlschlag ist nie „nichts"
 *
 * Beim Lesen traegt {@see Zuweisungsliste} mit, ob der Speicher zu sprechen
 * war. Beim Schreiben ist `null` die Antwort auf „ging nicht" — und der
 * Aufrufer MUSS das als Fehlschlag weitergeben. Eine Zuweisung, die als
 * gelungen gemeldet wird und nirgends steht, ist schlimmer als eine
 * Fehlermeldung: Der Zuweisende glaubt, die Sache ist vergeben.
 */
interface Zuweisungsspeicher
{
    /**
     * Kann hier ueberhaupt zugewiesen werden?
     *
     * Ein Speicher kann lesend sein — etwa eine Mitte, die gerade nur
     * herausgibt. Die Oberflaeche fragt das, bevor sie die Auswahl anbietet;
     * ein Knopf, der sicher scheitert, ist schlechter als keiner.
     */
    public function schreibbar(): bool;

    /**
     * Die offenen Zuweisungen dieses Postfachs.
     */
    public function offene(int $konto): Zuweisungsliste;

    /**
     * Die Zuweisung an diesem Vorgang — oder `null`, wenn es keine gibt.
     *
     * Gebraucht fuer die Frage „wer war es VORHER". Sie muss vor dem Schreiben
     * gestellt werden, sonst steht dort schon die neue Person und der
     * Vergleich geht immer aus — und ein Produkt, das nur beim WECHSEL
     * benachrichtigt, benachrichtigt dann nie.
     */
    public function fuer(int $konto, string $nachricht, ?string $kette): ?Zuweisung;

    /**
     * Zuweisen. `null` heisst: ging nicht.
     */
    public function zuweisen(int $konto, string $nachricht, ?string $kette, Person $an, ?Person $von): ?Zuweisung;

    /**
     * Aufheben. `false` heisst: ging nicht.
     *
     * Auch hier gilt: Ein Aufheben, das als gelungen gemeldet wird und nicht
     * stattfand, laesst die Sache bei jemandem liegen, der sie abgegeben
     * glaubt.
     */
    public function aufheben(int $konto, string $nachricht, ?string $kette): bool;
}
