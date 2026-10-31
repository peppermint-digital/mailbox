<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Werkzeug, dessen Auswahl GESUCHT werden muss.
 *
 * ## Warum es diesen Vertrag braucht
 *
 * {@see LiefertFelder} liefert eine fertige Liste, und das geht, solange sie
 * kurz ist: „wer ist zustaendig" sind fuenf Kollegen. Es geht nicht bei einem
 * Kontakt aus zwoelftausend, bei einer Aufgabe aus achthundert, bei einem
 * Projekt mit Unterprojekten. Dafuer gab es bis v0.200.0 nur `verweis()` — das
 * Verb wurde zum Link und fuehrte ins andere System.
 *
 * Genau diesen Sprung baut #9145 ab. Bastians Satz: „In jedem System muss der
 * Mailbrowser gleich aussehen, mit der gleichen Werkzeugleiste, alles koennen
 * und gleich funktionieren."
 *
 * ## Warum eine dritte Schnittstelle und keine Methode an `Vorgangswerkzeug`
 *
 * Dieselbe Begruendung wie bei {@see LiefertFelder}, und sie wiegt inzwischen
 * mehr: Es gibt Produkt-Werkzeuge in drei Produkten, und eine Methode mehr am
 * Vertrag bricht sie alle beim naechsten Paket-Update — fuer eine Faehigkeit,
 * die die meisten Verben nie brauchen. Gefragt wird per `instanceof`: Wer
 * suchen kann, sagt es.
 *
 * ## Warum KEINE neue Faehigkeit
 *
 * Die Suche laeuft unter `mailbox.actions`, nicht unter `mailbox.actions.run`.
 * Sie LIEST, was moeglich ist, und dafuer gibt es die Faehigkeit schon. Eine
 * dritte waere eine dritte Stelle, an der ein Produkt sie vergessen kann — und
 * das stille Ergebnis waere ein Auswaehler, der nie Treffer findet, was wie
 * „den Kontakt gibt es nicht" aussieht.
 *
 * ## Die Regeln, die hier gelten und nicht verhandelbar sind
 *
 * **Suchen ist LESEN, aber kein Freibrief.** Die Treffer gehen ueber
 * Systemgrenzen, und `anwendbar()` ist nach wie vor keine Rechtepruefung —
 * eine Liste, die durch vier Systeme gereist ist, ist eine Behauptung. Wer
 * hier Kontakte herausgibt, muss selbst pruefen, welche diese Person sehen
 * darf. Sonst ist eine Werkzeugleiste ein Namensverzeichnis fuer jeden, der
 * eine Mail offen hat.
 *
 * **Die Suche muss eine leere Frage ertragen.** Der Auswaehler fragt beim
 * Aufklappen einmal ohne Text, um etwas zu zeigen zu haben. Antworte mit dem,
 * was ohne Frage sinnvoll ist (die letzten, die naechsten, die eigenen) — oder
 * mit einer leeren Liste, aber nicht mit allem.
 *
 * **Begrenze selbst.** Das Paket schneidet die Liste nicht ab, weil es nicht
 * weiss, was eine gute Reihenfolge ist. Zwanzig Treffer sind eine Auswahl,
 * zweitausend sind eine Leitung, die zumacht.
 */
interface LiefertAuswahl
{
    /**
     * Treffer zu einer Frage — fuer DIESES Feld und DIESEN Vorgang.
     *
     * Der Feldname kommt aus {@see LiefertFelder::felder()}: Ein Verb mit
     * zwei suchbaren Feldern („Projekt", „Bearbeiter") wird zweimal gefragt
     * und muss die beiden unterscheiden koennen.
     *
     * Der Vorgang reist mit, weil die Antwort oft daran haengt: Welche
     * Aufgaben in Frage kommen, entscheidet sich am Postfach und manchmal am
     * Absender der Mail.
     *
     * @param  string  $feld  Name des Feldes aus `felder()`
     * @param  string  $suche  Was der Mensch getippt hat; `''` beim Aufklappen
     * @return array<int, array{wert: string, wort: string, hinweis?: string}>
     *                                                                         `hinweis` steht klein unter dem Wort — fuer das,
     *                                                                         was zwei gleiche Namen unterscheidet
     */
    public function auswahl(Vorgang $vorgang, Model $person, string $feld, string $suche): array;
}
