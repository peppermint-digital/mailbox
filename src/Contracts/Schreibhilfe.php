<?php

namespace Peppermint\Mailbox\Contracts;

/**
 * Text verbessern lassen — korrigieren oder umformulieren.
 *
 * ## Warum das eine Schnittstelle ist und kein Dienst
 *
 * Dieselbe Regel wie bei {@see Gespraechsveredler}: Das Paket soll **ohne
 * Modell laufen, ohne Schluessel, ohne fremden Dienst.** Was hier steht, ist
 * die Moeglichkeit; die Entscheidung, WELCHES Modell antwortet und ob
 * ueberhaupt eines da ist, gehoert dem Produkt.
 *
 * Bis zum 31.10.2026 hatte nur der Projekt-Manager einen Schreibassistenten,
 * in seinem eigenen Editor-Wrapper. Die anderen drei hatten keinen — nicht
 * weil es jemand so entschieden haette, sondern weil er dort gewachsen ist.
 * Das ist dieselbe Ungleichheit wie bei den Entwuerfen, und Bastians Regel
 * dazu ist eindeutig: in jedem System die Funktionen aller Systeme.
 *
 * ## Was der Dienst NICHT bekommt
 *
 * Keine Signatur. Sie wird vorne herausgeschnitten und hinterher wieder
 * angehaengt — ein Modell, das „freundlicher" formulieren soll, formuliert
 * sonst die Grussformel um, und das ist nie gemeint. Welcher Teil die
 * Signatur ist, weiss nur die Oberflaeche; hier kommt bereits der Text an,
 * um den es geht.
 */
interface Schreibhilfe
{
    /**
     * Den Text verarbeiten.
     *
     * @param  string  $text  reiner Text, ohne Signatur
     * @param  'correct'|'rephrase'  $modus
     * @param  'formal'|'friendly'|'shorter'|null  $stil  nur bei `rephrase`
     * @return string der neue Text, ebenfalls rein
     *
     * @throws \RuntimeException wenn der Dienst nicht antwortet — der Aufrufer
     *                           macht daraus eine Absage, keinen Absturz
     */
    public function verarbeiten(string $text, string $modus, ?string $stil = null): string;
}
