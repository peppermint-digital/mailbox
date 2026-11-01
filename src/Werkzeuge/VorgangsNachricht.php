<?php

namespace Peppermint\Mailbox\Werkzeuge;

use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Mailboxes;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * Welche Mail ein Vorgang meint — und der Nachweis, dass es diese ist (#9145).
 *
 * ## Das Loch, das diese Klasse schliesst
 *
 * Ein {@see Vorgang} benennt die Mail fachlich: Postfach-ADRESSE,
 * Kettenkennung, `Message-ID`. Das ist richtig so, und es ist die erste Falle
 * in `docs/INTEGRATION.md` — eine Konto-Nummer quer ueber Systemgrenzen
 * stimmt zufaellig, bis sie die falsche Unterhaltung liefert.
 *
 * Nur: Ein Verb, das die Mail selbst braucht, kommt damit nicht an sie heran.
 * Drei Verben brauchen sie:
 *
 *   - „Als Eingangsrechnung" braucht die BYTES eines Anhangs.
 *   - „Aufgabe anhaengen" lagert Rumpf und Anhaenge ein.
 *   - „Ticket erstellen" schreibt den Rumpf in die Beschreibung.
 *
 * Alle drei fuehrten deshalb bis heute nur HIN (`verweis()`), oder sie nahmen
 * den Rumpf als mitgeteilte Angabe — und die war leer, weil sie nie jemand
 * gefuellt hat.
 *
 * ## Zwei Wege, und der erste ist eine Behauptung
 *
 * 1. **Mitgeteilt.** Das fragende System legt Ordner und Kennung der offenen
 *    Mail in {@see Vorgang::$zusatz} (`folder`, `uid`). Das ist billig und
 *    trifft fast immer — es ist die Mail, die der Mensch gerade offen hat.
 *    Es ist aber eine BEHAUPTUNG: Ein fremdes System kann dasselbe Postfach
 *    ueber ein anderes Protokoll ansprechen (JMAP-Kennungen sind keine
 *    IMAP-UIDs), und nach einem Wechsel der `UIDVALIDITY` zeigt dieselbe
 *    Zahl auf eine andere Mail.
 *
 *    Deshalb wird die geholte Nachricht IMMER an ihrer `Message-ID` gegen den
 *    Vorgang geprueft. Stimmt sie nicht, gilt die Angabe als nicht vorhanden.
 *    Ohne diese Pruefung waere der ganze Weg genau der stille Fehler, den die
 *    Adress-statt-Nummer-Regel verhindert: der falsche Anhang, verbucht,
 *    unbemerkt.
 *
 * 2. **Gesucht.** Ohne brauchbare Angabe wird die `Message-ID` im Postfach
 *    gesucht ({@see Mailbox::findByToken()}) — Volltext, weil IMAP nichts
 *    anderes anbietet, und jeder Treffer danach wieder an seiner
 *    `Message-ID` geprueft, sonst waere eine Mail, die die Kennung bloss
 *    zitiert, der Fund.
 *
 * Der zweite Weg kostet eine Suche je Aufruf. Er ist der Rueckfall und nicht
 * der Normalfall — aber er ist der Grund, dass ein Verb auch dann laeuft,
 * wenn das fragende System die Angaben nicht mitschickt (eine aeltere
 * Fassung, ein Aufruf aus einem Skript).
 *
 * ## Was diese Klasse NICHT tut
 *
 * **Sie sucht das Konto nicht.** Das Produkt gibt es herein, weil nur das
 * Produkt weiss, ob diese PERSON dieses Postfach sehen darf — und weil genau
 * diese Pruefung zweimal umgangen wurde, indem eine Sichtbarkeitsregel
 * nachgebildet statt gefragt wurde (Memories #4003, #4009). Ein Helfer, der
 * sich das Konto selbst aus dem Speicher holt, wuerde diese Entscheidung aus
 * dem Verb herausnehmen, in dem sie hingehoert.
 *
 * **Sie entscheidet nichts ueber Rechte.** Wer hier hereinkommt, hat sie
 * schon geprueft. `ausfuehren()` ist die Stelle, nicht diese.
 */
final class VorgangsNachricht
{
    /**
     * Wie viele Volltext-Treffer der Rueckfall ansieht.
     *
     * Klein mit Absicht: Der Weg ist der Rueckfall, und eine `Message-ID` ist
     * eindeutig. Mehr Treffer heissen nicht „genauer", sie heissen „die
     * Kennung steht in vielen Mails" — und dann ist der erste geprueffte
     * Treffer so gut wie der zwanzigste.
     */
    private const TREFFER = 10;

    /**
     * Welche Ordner der Rueckfall durchsucht, wenn keiner mitgeteilt wurde.
     *
     * Der Posteingang zuerst, weil dort liegt, woran gerade gearbeitet wird.
     * Keine Suche ueber ALLE Ordner: Das ist auf Exchange eine Volltextsuche
     * je Ordner, und eine Mail, die jemand schon weggeraeumt hat, ist nicht
     * der Fall, fuer den ein Verb in der Leiste steht.
     */
    private const ORDNER = ['INBOX'];

    /**
     * Die Mail dieses Vorgangs im Postfach dieses Kontos — oder `null`.
     *
     * `null` heisst „in diesem Postfach ist diese Mail nicht (mehr) zu
     * finden". Das ist eine Auskunft und kein Fehler: Ein Verb antwortet
     * darauf mit einem Satz, nicht mit einer Ausnahme.
     */
    public function finden(Vorgang $vorgang, MailAccount $konto, ?Mailbox $postfach = null): ?Nachrichtenfund
    {
        if ($vorgang->nachricht === '') {
            return null;
        }

        $postfach ??= Mailboxes::for($konto);

        return $this->mitgeteilt($vorgang, $postfach) ?? $this->gesucht($vorgang, $postfach);
    }

    /**
     * Weg 1: Ordner und Kennung, die das fragende System mitgeschickt hat.
     *
     * Und die Pruefung dahinter, die den Weg erst benutzbar macht.
     */
    private function mitgeteilt(Vorgang $vorgang, Mailbox $postfach): ?Nachrichtenfund
    {
        $ordner = $vorgang->zusatz['folder'] ?? null;
        $kennung = $vorgang->zusatz['uid'] ?? null;

        if (! is_string($ordner) || trim($ordner) === '') {
            return null;
        }

        if (! is_int($kennung) && ! (is_string($kennung) && trim($kennung) !== '')) {
            return null;
        }

        return $this->geprueft($postfach, trim($ordner), is_int($kennung) ? $kennung : trim($kennung), $vorgang);
    }

    /**
     * Weg 2: die `Message-ID` im Postfach suchen.
     */
    private function gesucht(Vorgang $vorgang, Mailbox $postfach): ?Nachrichtenfund
    {
        $ordner = $vorgang->zusatz['folder'] ?? null;
        $zuSuchen = is_string($ordner) && trim($ordner) !== '' ? [trim($ordner)] : self::ORDNER;

        // Die spitzen Klammern der Kennung gehoeren nicht in eine
        // Volltextsuche: Manche Server liefern sie mit, manche nicht, und
        // `findByToken` prueft den Treffer ohnehin selbst nach.
        $kennung = trim($vorgang->nachricht, '<> ');

        if ($kennung === '') {
            return null;
        }

        foreach ($zuSuchen as $name) {
            foreach ($postfach->findByToken($name, $kennung, self::TREFFER) as $uid) {
                $fund = $this->geprueft($postfach, $name, $uid, $vorgang);

                if ($fund !== null) {
                    return $fund;
                }
            }
        }

        return null;
    }

    /**
     * Holen und an der `Message-ID` pruefen — die eine Stelle dafuer.
     */
    private function geprueft(Mailbox $postfach, string $ordner, int|string $kennung, Vorgang $vorgang): ?Nachrichtenfund
    {
        $nachricht = $postfach->message($ordner, $kennung);

        if ($nachricht === null) {
            return null;
        }

        if (! $this->gleicheKennung((string) ($nachricht['message_id'] ?? ''), $vorgang->nachricht)) {
            return null;
        }

        return new Nachrichtenfund($postfach, $ordner, $kennung, $nachricht);
    }

    /**
     * Ist das dieselbe `Message-ID`?
     *
     * Die spitzen Klammern sind nach RFC 5322 Teil der Kennung, und manche
     * Server geben sie mit heraus, manche nicht — dasselbe gilt fuer das
     * fragende System. Daran eine Pruefung scheitern zu lassen heisst, den
     * sicheren Weg abzuschalten und in die Suche zu fallen, ohne dass es
     * jemand merkt. Die Gross-/Kleinschreibung bleibt dagegen bedeutsam: Der
     * linke Teil einer Kennung ist nach RFC genau so zu nehmen, wie er steht.
     */
    private function gleicheKennung(string $eine, string $andere): bool
    {
        $eine = trim($eine, '<> ');
        $andere = trim($andere, '<> ');

        return $eine !== '' && $eine === $andere;
    }
}
