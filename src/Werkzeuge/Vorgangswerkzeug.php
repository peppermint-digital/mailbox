<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Illuminate\Database\Eloquent\Model;

/**
 * Was dieses System an einer Mail tun kann.
 *
 * Ein Produkt meldet je Verb eines an. Das Paket fragt es und weiss nicht,
 * was dahinter liegt — ein Ticket, ein Beleg, eine Aufgabe, ein Kontakt.
 *
 * ## Drei Methoden, und die Reihenfolge ist Absicht
 *
 * 1. `werkzeug()` — die Form. Beim Start bekannt, haengt an keiner Mail.
 * 2. `anwendbar()` — darf diese Person das an DIESEM Vorgang? Entscheidet
 *    ueber Erscheinen oder Nicht-Erscheinen in der Leiste.
 * 3. `ausfuehren()` — tun.
 *
 * ## `anwendbar()` ist keine Rechtepruefung — `ausfuehren()` schon
 *
 * Das ist die wichtigste Regel hier, und sie ist teuer erkauft. Zweimal hat
 * dieses Projekt fremde Posteingaenge geoeffnet, weil eine Sichtbarkeitsregel
 * als Recht gelesen wurde (Memories #4003, #4009). Dasselbe Muster droht
 * hier, eine Ebene hoeher: Die Leiste wird ueber Systemgrenzen
 * zusammengelegt, und eine Liste, die durch vier Systeme gereist ist, ist
 * eine Behauptung.
 *
 * Also: `anwendbar()` sagt, ob der Knopf sinnvoll ist. Ob der Vorgang
 * zulaessig ist, prueft `ausfuehren()` erneut und selbst — jedes Mal, auch
 * wenn das Werkzeug gerade aus der eigenen Liste kommt.
 *
 * ## Nur die EIGENEN Verben
 *
 * Ein Werkzeug gehoert dem System, das es anmeldet. Was die anderen koennen,
 * holt {@see FremdeWerkzeuge} ueber die Faehigkeit `mailbox.actions`. Wuerde
 * ein Produkt fremde Verben mitanmelden, kaeme jedes ueber zwei Wege herein —
 * und der Verbund fragte sich im Kreis.
 */
interface Vorgangswerkzeug
{
    /**
     * Die Form dieses Verbs — ohne Zustand, ohne System.
     *
     * Beides wird spaeter angehaengt: der Zustand vom Vorgang, das System vom
     * fragenden.
     */
    public function werkzeug(): Werkzeug;

    /**
     * Soll der Knopf fuer diesen Vorgang und diese Person erscheinen?
     *
     * `false` heisst „hier nicht sinnvoll" — eine Mail ohne Anhang braucht
     * kein „Als Eingangsrechnung". Keine Rechtepruefung, siehe Klassentext.
     */
    public function anwendbar(Vorgang $vorgang, Model $person): bool;

    /**
     * Gibt es die Sache schon?
     *
     * Rueckgabe `null` heisst „noch nicht" — der Knopf bietet das Verb an.
     * Sonst zeigt die Leiste den Verweis statt des Verbs, und niemand legt
     * dasselbe Ticket zweimal an.
     *
     * Das Paket kann diese Frage fuer kein Verb beantworten. Nur das System,
     * das die Verknuepfung fuehrt, weiss es.
     *
     * @return array{wort: string, verweis?: string|null}|null
     */
    public function zustand(Vorgang $vorgang, Model $person): ?array;

    /**
     * Wohin dieses Verb fuehrt — oder `null`, wenn es TUT.
     *
     * ## Warum nicht in `werkzeug()`
     *
     * Weil die Adresse an der MAIL haengt und `werkzeug()` den Vorgang nicht
     * kennt: Es beschreibt die Form, die beim Start feststeht. „Im CRM
     * zuordnen" fuehrt aber nicht irgendwohin, sondern an genau diese
     * Konversation — ein Verweis auf die Uebersicht laesst den Menschen davor
     * erst suchen, und dann war der Klick umsonst.
     *
     * Aufgefallen beim ersten echten Verweis-Verb (19.10.2026): Der Entwurf
     * hatte die Adresse in `werkzeug()` stehen und konnte dort nur raten.
     *
     * Ein Verb, das TUT, gibt hier `null` zurueck. Beides zu liefern ist ein
     * Widerspruch — es wird dann als Verweis behandelt und nie ausgeloest.
     */
    public function verweis(Vorgang $vorgang, Model $person): ?string;

    /**
     * Tun — und dabei die Zulaessigkeit selbst pruefen.
     *
     * Ein Fehlschlag ist {@see Ergebnis::gescheitert()}, keine Ausnahme: Das
     * Ticketsystem antwortet eben manchmal nicht, und das ist kein
     * Programmfehler.
     *
     * @param  array<string, mixed>  $daten  Was die Felder des Werkzeugs eingesammelt haben.
     */
    public function ausfuehren(Vorgang $vorgang, array $daten, Model $person): Ergebnis;
}
