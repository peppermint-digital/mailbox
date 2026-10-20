<?php

namespace Peppermint\Mailbox\Contracts;

use Peppermint\Mailbox\Http\HandlesAssignments;

/**
 * Wem dieses System eine Unterhaltung zuweisen kann.
 *
 * ## Warum das ein Vertrag ist und keine Methode am Controller
 *
 * Die Liste gab es schon — als `assignableUsers()` in {@see HandlesAssignments}.
 * Dort ist sie an einen Controller gebunden, und das reichte, solange sie
 * genau einen Leser hatte: die Zuweisungs-Oberflaeche der Mailseite.
 *
 * Mit dem Werkzeug „Person zuordnen" bekam sie einen zweiten. Ein Werkzeug
 * erreicht keinen Controller — es wird vom Verzeichnis gebaut. Die naechste
 * Abfrage haette also eine ZWEITE Liste gebraucht, und zwei Listen derselben
 * Menge laufen auseinander: Die eine wird gepflegt, die andere nicht, und es
 * faellt erst auf, wenn jemand in einem der beiden Wege fehlt.
 *
 * Also eine Quelle, zwei Leser.
 *
 * ## Die Liste IST die Pruefung
 *
 * Wer nicht darin steht, bekommt nichts zugewiesen — nicht als zweite
 * Abfrage, sondern weil beide Wege nur aus dieser Liste waehlen. Deshalb darf
 * sie nicht „alle Nutzer" sein, wenn das Produkt engere Regeln hat.
 *
 * Gemessen am 20.10.2026: Der Manager fragt `$konto->assignedUsers()` und
 * bleibt damit bei denen, die das Postfach ueberhaupt sehen. CRM, Verwaltung
 * und AI Brain geben ALLE Nutzer heraus — dort kann eine Mail jemandem
 * zugewiesen werden, der sie nicht oeffnen kann. Das ist eine eigene Frage
 * (und vermutlich ein Fehler), aber keine, die dieser Vertrag entscheidet: Er
 * macht nur sichtbar, dass es eine Entscheidung ist.
 *
 * ## Ohne Adresse geht es nicht
 *
 * Die Zustaendigkeit liegt in der Mitte und wird dort ueber die E-Mail-Adresse
 * gefuehrt. Eine Nummer ist je System ein anderer Mensch. Ein Eintrag ohne
 * Adresse darf hier stehen — er wird dann sichtbar abgelehnt, statt still
 * nicht zugewiesen zu werden.
 */
interface Zuweisbare
{
    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    public function fuer(int $konto): array;
}
