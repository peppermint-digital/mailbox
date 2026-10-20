<?php

namespace Peppermint\Mailbox\Contracts;

use DateTimeInterface;
use Peppermint\Mailbox\Zustand\Person;
use Peppermint\Mailbox\Zustand\Schlummerliste;

/**
 * Wo steht, was jemand weggelegt hat.
 *
 * Dasselbe Muster wie {@see Zuweisungsspeicher}, und aus demselben Grund
 * zentral: „Ich habe diese Unterhaltung weggelegt" ist eine Aussage ueber
 * mich und die Sache — in jedem System dieselbe.
 *
 * - {@see \Peppermint\Mailbox\Stores\LokalerSchlummerspeicher} — eigene Tabelle
 * - {@see \Peppermint\Mailbox\Stores\BrainSchlummerspeicher} — ueber die Bridge
 *
 * ## Die Kette ist Pflicht, nicht die Nachricht
 *
 * Eine einzelne Nachricht zu verstecken hiesse: Die naechste Antwort in
 * derselben Sache taucht sofort wieder auf, und der Mensch davor haelt das
 * Schlummern fuer kaputt. Wer keine Kette bestimmen kann, bekommt eine
 * ehrliche Absage — das entscheidet aber der aufrufende Weg, nicht der
 * Speicher.
 */
interface Schlummerspeicher
{
    /**
     * Was fuer diese Person in diesem Postfach gerade ruht.
     */
    public function ruhende(int $konto, Person $person): Schlummerliste;

    /**
     * Weglegen. `false` heisst: ging nicht.
     *
     * Ein Weglegen, das als gelungen gemeldet wird und nicht stattfand, laesst
     * die Nachricht stehen — aergerlich, aber sichtbar. Die umgekehrte Luege
     * waere schlimmer, deshalb wird hier trotzdem nicht geraten.
     */
    public function weglegen(int $konto, Person $person, string $kette, string $nachricht, DateTimeInterface $bis): bool;

    /**
     * Wieder hervorholen. `false` heisst: ging nicht.
     *
     * Und das ist der Fall, der wirklich weh tut: Wer etwas hervorholt und es
     * bleibt weg, sucht eine Nachricht, die er sich selbst versteckt hat.
     */
    public function hervorholen(int $konto, Person $person, ?string $kette, string $nachricht): bool;
}
