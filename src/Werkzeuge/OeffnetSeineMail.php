<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Mailbox\Http\HandlesMailboxActions;

/**
 * Ein Werkzeug, das die Mail seines Vorgangs OEFFNEN kann (#9856).
 *
 * ## Das Loch, das dieser Vertrag schliesst
 *
 * {@see LiefertFelder} fuehrt eine Tabelle, und in ihr steht eine Zusage:
 *
 * | `art` | Was die Leiste zeichnet | Wer die Auswahl liefert |
 * |---|---|---|
 * | `anhang` | die Anhaenge DIESER Mail | **das PAKET** |
 *
 * Diese Zusage galt nur fuer die EIGENE Leiste. Dort stimmt sie auch: Die
 * Oberflaeche hat die Mail offen und kennt ihre Anhaenge, ohne jemanden zu
 * fragen. Fuer ein anderes System galt sie nicht — und das war kein
 * Schoenheitsfehler, sondern ein toter Pflichtknopf.
 *
 * Gemessen auf Produktion am 12.11.2026 (Verwaltung, Bug #1446): Eine Biene
 * wollte eine Rechnung aus einer Mail verbuchen, bekam das Verb `beleg`
 * korrekt angeboten und fuer sein Pflichtfeld `anhang` dreimal eine LEERE
 * Auswahl. Im Log stand jedes Mal:
 *
 *     Mail-Werkzeug hat ein suchbares Feld, aber keinen Suchvertrag.
 *     {"tool":"App\Mailbox\BelegAusMail","key":"beleg","field":"anhang"}
 *
 * Die Feldsuche gab die Feldart `anhang` an {@see LiefertAuswahl} weiter —
 * also an einen Vertrag, den ein Verb fuer DIESE Art gar nicht haben soll.
 * Zehn Belege blieben deshalb liegen, und der Fragende sah „diese Mail hat
 * keine Anhaenge", wo „ich kann dir die Liste hier nicht geben" richtig
 * gewesen waere.
 *
 * ## Warum der Vertrag das Verb fragt und nicht das Paket selbst nachsieht
 *
 * Weil die Mail ohne Postfach nicht zu holen ist, und das Postfach ist eine
 * RECHTEFRAGE. {@see VorgangsNachricht} sagt es ausdruecklich: „Sie sucht das
 * Konto nicht. Das Produkt gibt es herein, weil nur das Produkt weiss, ob
 * diese PERSON dieses Postfach sehen darf — und weil genau diese Pruefung
 * zweimal umgangen wurde, indem eine Sichtbarkeitsregel nachgebildet statt
 * gefragt wurde."
 *
 * Dieselbe Trennung wie bei {@see HandlesMailboxActions}:
 * Das Paket macht die Arbeit, das Produkt sagt, WELCHES Postfach das ist und
 * ob es dem Anfragenden zusteht. Ein Paket, das sich das Konto selbst aus
 * einer Ablage holt, waere bequem und hebelte die Rechte aus.
 *
 * ## Warum ein dritter Vertrag und keine Methode an `Vorgangswerkzeug`
 *
 * Dieselbe Begruendung wie bei {@see LiefertFelder} und {@see LiefertAuswahl},
 * und sie wiegt unveraendert: Es gibt Produkt-Werkzeuge in drei Produkten, und
 * eine Pflichtmethode mehr bricht sie alle beim naechsten Paket-Update — fuer
 * eine Faehigkeit, die die meisten Verben nie brauchen. Gefragt wird per
 * `instanceof`: Wer seine Mail oeffnen kann, sagt es.
 *
 * ## Wer das umsetzen MUSS
 *
 * Jedes Verb mit einem Feld der Art `anhang`. Ohne diesen Vertrag bleibt die
 * Auswahl dort leer, und das Verb ist von aussen nicht ausloesbar — die
 * Pflichtangabe ist nicht einzusammeln.
 *
 * Teuer ist es nicht: Ein solches Verb MUSS die Mail ohnehin oeffnen koennen,
 * sonst haette es keine Bytes zum Verbuchen. In der Verwaltung sind es drei
 * Zeilen, weil `BelegAusMail` sein Konto schon aufloest und
 * {@see VorgangsNachricht} schon benutzt.
 */
interface OeffnetSeineMail
{
    /**
     * Die Mail dieses Vorgangs — oder `null`, wenn sie nicht (mehr) zu finden
     * ist oder diese Person das Postfach nicht sehen darf.
     *
     * `null` ist eine Auskunft und kein Fehler: Die Feldsuche antwortet darauf
     * mit einer leeren Liste, so wie auf jede andere Absage auch. Der Grund
     * gehoert ins Protokoll des Produkts, nicht in die Antwort — eine
     * Auswahlliste, die erklaert, WARUM sie leer ist, erzaehlt dem Fragenden
     * etwas ueber ein Postfach, das er vielleicht nicht sehen darf.
     *
     * Die zurueckgegebene {@see Nachrichtenfund} ist bereits an ihrer
     * `Message-ID` gegen den Vorgang geprueft — das tut
     * {@see VorgangsNachricht::finden()}, und ein Verb soll es nicht
     * nachbauen.
     */
    public function nachricht(Vorgang $vorgang, Model $person): ?Nachrichtenfund;
}
