<?php

namespace Peppermint\Mailbox\Contracts;

/**
 * Woher der Gespraechsverlauf kommt.
 *
 * ## Dieselbe Aufteilung wie bei den Postfaechern
 *
 * Die Ablage ist EINE Wahrheit — sie liegt dort, wo archiviert wird, und
 * nirgends sonst. Zwei Ablagen waeren zwei Wahrheiten, und die laufen
 * auseinander.
 *
 * Sehen koennen soll den Verlauf trotzdem jedes System. Also dieselbe Loesung
 * wie bei {@see AccountStore}: Wer ihn selbst fuehrt, liest lokal; wer nicht,
 * fragt die Mitte.
 *
 * - {@see \Peppermint\Mailbox\Stores\LokalerVerlaufsspeicher} — eigene Tabellen
 * - {@see \Peppermint\Mailbox\Stores\BrainVerlaufsspeicher} — ueber die Bridge
 *
 * ## Warum nicht einfach die Tabellen mitliefern
 *
 * Weil dann jedes Produkt seine eigene Ablage fuehrte: derselbe Posteingang
 * viermal abgeholt, viermal gespeichert, viermal gegen die Drosselung des
 * Anbieters. Und der Verlauf saehe in jedem System anders aus, je nachdem, wer
 * wann zuletzt gelaufen ist.
 */
interface Verlaufsspeicher
{
    /**
     * Gibt es zu dieser Kette einen VOLLSTAENDIGEN Verlauf?
     *
     * Vollstaendig heisst: Der Anfang der Kette liegt in der Ablage. Ein Chat,
     * der mit der dritten Antwort beginnt, sieht nicht aus wie
     * „unvollstaendig", sondern wie „so war es".
     *
     * Bei einer unerreichbaren Mitte `false` — und nicht etwa eine Ausnahme.
     * Der Knopf erscheint dann eben nicht; die normale Ansicht steht ja da.
     */
    public function verfuegbar(int $account, string $thread): bool;

    /**
     * Die Eintraege der Kette, aelteste zuerst.
     *
     * Leer, wenn es nichts gibt oder die Mitte nicht antwortet.
     *
     * @return list<array<string, mixed>>
     */
    public function verlauf(int $account, string $thread): array;
}
