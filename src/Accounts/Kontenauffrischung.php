<?php

namespace Peppermint\Mailbox\Accounts;

use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Contracts\VerwirftKonten;
use Peppermint\Mailbox\Exceptions\AnmeldungAbgelehnt;
use Peppermint\Mailbox\Models\MailAccount;
use Throwable;

/**
 * Nach einer abgelehnten Anmeldung: gespeicherten Kontenstand wegwerfen und neu lesen.
 *
 * ## Warum das hier steht und nicht zweimal in den Clients
 *
 * IMAP und JMAP haben dieselbe Lücke an derselben Stelle, und sie müssen sich
 * gleich verhalten. Läge die Entscheidung in beiden Clients, liefe sie
 * auseinander — und zwar still, weil ein Produkt meist nur einen der beiden
 * Transporte benutzt und die Abweichung deshalb niemandem auffällt. Dieselbe
 * Begründung wie bei {@see \Peppermint\Mailbox\Exceptions\NichtErreichbar}.
 *
 * ## Die drei Bedingungen, und warum jede einzelne nötig ist
 *
 * 1. **Der Fehler ist eine Ablehnung.** Sonst würde ein Leitungsfehler einen
 *    gesunden Kontenstand wegwerfen, den der nächste Aufruf teuer neu holt.
 * 2. **Die Ablage kann überhaupt verwerfen.** Ein Produkt mit eigener
 *    Kontotabelle hat keinen Zwischenspeicher; dort gibt es nichts
 *    aufzufrischen, und ohne diese Prüfung liefe jeder Anmeldefehler in einen
 *    zweiten, genauso aussichtslosen Versuch.
 * 3. **Es kommt wirklich etwas anderes zurück.** Liefert die Mitte dasselbe
 *    Token noch einmal, ist der zweite Versuch dieselbe Frage mit denselben
 *    Daten — dann lieber sofort den ursprünglichen Fehler zeigen.
 *
 * Punkt 3 ist der, den man weglässt und später sucht: Ohne ihn verdoppelt sich
 * bei einem Postfach, dessen Zustimmung wirklich entzogen wurde, jede
 * Anmeldedauer, und der Mensch wartet doppelt so lange auf dieselbe Meldung.
 *
 * ## Was hier NICHT passiert
 *
 * Es wird **nicht erneuert**. Microsoft rotiert Erneuerungs-Tokens; erneuerte
 * das Produkt selbst, entwertete es das der Mitte, und der Schaden fiele beim
 * nächsten auf, der zugreift. Hier wird nur weggeworfen und neu gefragt — dass
 * ein gültiges Token zurückkommt, ist die Leistung der Mitte.
 */
final class Kontenauffrischung
{
    /**
     * Ein frisches Konto nach einer Ablehnung — oder null, wenn es keinen
     * zweiten Versuch wert ist.
     *
     * Der Aufrufer wiederholt genau dann, wenn hier ein Konto herauskommt, und
     * wiederholt genau einmal. Die Begrenzung liegt bei ihm und nicht hier:
     * Sie gehört an die Stelle, die die Sitzung führt.
     */
    public static function nachAblehnung(
        Throwable $fehler,
        ?AccountStore $ablage,
        MailAccount $konto,
    ): ?MailAccount {
        if (! $ablage instanceof VerwirftKonten) {
            return null;
        }

        if (! AnmeldungAbgelehnt::trifftZu($fehler)) {
            return null;
        }

        $kennung = $konto->field('id');

        if (! is_string($kennung) && ! is_int($kennung)) {
            // Ein Konto ohne Kennung laesst sich nicht wieder auffinden. Dann
            // auch nichts verwerfen: Ein geleerter Zwischenspeicher ohne die
            // Moeglichkeit, gezielt neu zu lesen, kostet nur alle anderen.
            return null;
        }

        $ablage->verwerfen(self::besitzer($konto));

        $frisch = $ablage->find($kennung);

        if (! $frisch instanceof MailAccount) {
            // Das Konto gibt es in der Mitte nicht mehr. Dann ist der
            // urspruengliche Fehler die ehrlichere Meldung als ein zweiter
            // Versuch, der an etwas anderem scheitert.
            return null;
        }

        return self::aenderteSich($konto, $frisch) ? $frisch : null;
    }

    private static function besitzer(MailAccount $konto): ?int
    {
        $besitzer = $konto->field('user_id');

        return $besitzer === null ? null : (int) $besitzer;
    }

    /**
     * Unterscheiden sich die Zugangsdaten überhaupt?
     *
     * Verglichen wird, was beim Anmelden zählt — nicht das ganze Konto. Ein
     * geänderter Anzeigename macht einen zweiten Versuch nicht sinnvoller.
     */
    private static function aenderteSich(MailAccount $alt, MailAccount $neu): bool
    {
        foreach (['oauth_access_token', 'password', 'username'] as $feld) {
            if ($alt->field($feld) !== $neu->field($feld)) {
                return true;
            }
        }

        return false;
    }
}
