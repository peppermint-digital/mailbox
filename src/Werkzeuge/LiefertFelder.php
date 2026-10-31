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
 * In der Drahtform heissen sie `name`, `label`, `type`, `required`, `options`,
 * `multiple` — die inneren Namen gehen NICHT hinaus. Dieselbe Regel wie beim
 * Zustand: Sonst schickt dieses System `wort` und das naechste liest `label`,
 * und die Angabe faellt lautlos weg.
 *
 * ## Welche Arten es gibt
 *
 * | `art` | Was die Leiste zeichnet | Wer die Auswahl liefert |
 * |---|---|---|
 * | `select` | Klappliste | DU, in `auswahl` |
 * | `text` | eine Zeile | niemand |
 * | `anhang` | die Anhaenge DIESER Mail | das PAKET |
 *
 * `anhang` ist der Grund, warum diese Tabelle hier steht: Die Anhaenge kennt
 * das Paket schon, es zeigt sie an. Sie noch einmal von jedem Produkt
 * einsammeln zu lassen waere dieselbe Liste zum zweiten Mal, aus einer
 * schlechteren Quelle — das Produkt muesste die Mail dafuer selbst holen.
 * Also: `['name' => 'anhang', 'wort' => 'Anhang', 'art' => 'anhang']` und
 * KEIN `auswahl`. Was dort stehen wuerde, wird ignoriert.
 *
 * Geliefert wird der Dateiname. Nicht der Index: Der stimmt nur, solange
 * beide Seiten dieselbe Mail in derselben Reihenfolge sehen, und bei einer
 * Mail aus einem fremden System ist das eine Annahme, keine Tatsache.
 *
 * `mehrfach => true` macht aus der Auswahl eine Mehrfachauswahl; dann kommt
 * eine LISTE von Werten an, auch bei nur einem Haken. Ohne die Angabe kommt
 * ein einzelner Wert — wer beides lesen will, bekommt genau den Fehler, den
 * `art` vermeiden soll.
 *
 * Eine Art, die diese Liste nicht kennt, ist kein Fehler und kein toter
 * Knopf: Die Leiste bietet das Verb dann nicht an und SAGT, dass sie es nicht
 * zeichnen kann. Ein stilles Weglassen sieht aus wie „gibt es nicht", und
 * dann sucht jemand den Fehler bei sich.
 */
interface LiefertFelder
{
    /**
     * Was vor dem Ausloesen gefragt wird — fuer DIESEN Vorgang.
     *
     * Eine leere Liste heisst „ein Klick genuegt" und ist der Normalfall.
     *
     * @return array<int, array{name: string, wort: string, art: string, pflicht?: bool, mehrfach?: bool, auswahl?: array<int, array{wert: string, wort: string}>}>
     */
    public function felder(Vorgang $vorgang, Model $person): array;
}
