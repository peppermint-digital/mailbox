<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Werkzeug, dessen Eingabefelder am VORGANG haengen.
 *
 * ## Warum das eine zweite Schnittstelle ist und keine vierte Methode
 *
 * `Vorgangswerkzeug` hat drei Produkt-Werkzeuge in drei Produkten. Eine
 * Methode dort hinzuzufuegen bricht alle drei beim naechsten Paket-Update —
 * fuer eine Faehigkeit, die die meisten Verben nie brauchen. Also ein
 * zusaetzlicher Vertrag, gefragt per `instanceof`: Wer Felder hat, sagt es.
 *
 * ## Warum die Felder nicht in `werkzeug()` stehen koennen
 *
 * Dieselbe Begruendung wie bei `verweis()` und `zustand()`, und sie ist beim
 * ersten echten Fall aufgefallen: `werkzeug()` beschreibt die FORM, die beim
 * Start feststeht, und kennt den Vorgang nicht. Die Auswahl „welche Person"
 * haengt aber am POSTFACH — wer an `info@` zuweisen darf, ist eine andere
 * Menge als bei `buchhaltung@`. Eine Liste in `werkzeug()` koennte dort nur
 * raten, und geraten heisst hier: jemandem eine Mail zuweisen, der sie nicht
 * oeffnen kann.
 *
 * ## Die Form eines Feldes
 *
 * Absichtlich klein. Was die Leiste nicht zeichnen kann, bietet sie nicht als
 * Knopf an — ein Verb, das seine Pflichtangabe nicht einsammeln kann, ist ein
 * Knopf, der zuverlaessig scheitert.
 *
 *     ['name' => 'person', 'wort' => 'Zuständig', 'art' => 'select',
 *      'pflicht' => true, 'auswahl' => [['wert' => 'a@b.de', 'wort' => 'Anna']]]
 *
 * In der Drahtform heissen sie `name`, `label`, `type`, `required`, `options`
 * — die inneren Namen gehen NICHT hinaus. Dieselbe Regel wie beim Zustand:
 * Sonst schickt dieses System `wort` und das naechste liest `label`, und die
 * Angabe faellt lautlos weg.
 */
interface LiefertFelder
{
    /**
     * Was vor dem Ausloesen gefragt wird — fuer DIESEN Vorgang.
     *
     * Eine leere Liste heisst „ein Klick genuegt" und ist der Normalfall.
     *
     * @return array<int, array{name: string, wort: string, art: string, pflicht?: bool, auswahl?: array<int, array{wert: string, wort: string}>}>
     */
    public function felder(Vorgang $vorgang, Model $person): array;
}
