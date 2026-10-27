<?php

namespace Peppermint\Mailbox\Accounts;

use Illuminate\Support\Facades\Log;

/**
 * Wie lange ein Produkt einen Kontenstand aus der Mitte halten darf.
 *
 * ## Zwei Zahlen, die zueinander passen müssen (Task #8856, aus Bug #1098)
 *
 * Die Mitte garantiert, dass ein ausgegebenes Zugriffstoken noch eine
 * Mindestzeit gilt — in AI Brain ist das
 * `MailAccountTokenService::MINDESTLAUFZEIT_MINUTEN`, seit dem 26.10.2026
 * zwanzig Minuten. Das lesende Produkt hält die Kontenliste samt Token
 * `mailbox.cache_ttl` lang vor. Hält es sie länger, als die Mitte garantiert,
 * liegt ein **totes** Token im Zwischenspeicher, und jede Anmeldung daraus
 * scheitert mit „AUTHENTICATE failed" — am 26.10.2026 im Peppermint Manager
 * genau so aufgetreten.
 *
 * Die Zahlen wussten bisher nichts voneinander: Wer `MAILBOX_CACHE_TTL` über
 * die Mindestlaufzeit setzte, hebelte die Garantie aus, ohne dass irgendetwas
 * meckerte. Deshalb entscheidet nicht mehr die Konfiguration allein, sondern
 * das Paket deckelt sie.
 *
 * ## Warum hier gedeckelt wird und nicht nur gewarnt
 *
 * {@see \Peppermint\Mailbox\Accounts\Kontenauffrischung} fängt den Fall ab,
 * wenn er eingetreten ist: abgelehnte Anmeldung, Stand wegwerfen, einmal neu
 * lesen. Das ist eine Reparatur, und sie kostet pro Fenster einen sichtbaren
 * Fehlversuch. Der Deckel ist die Vermeidung.
 *
 * ## Warum nicht stillschweigend
 *
 * Ein Dienst, der eine zu große Eingabe kürzt statt sie abzulehnen, lässt den
 * nächsten Baustein dumm aussehen: Die Suche läuft dann in die falsche
 * Richtung, weil alles Erfolg meldet. Deshalb nennt die Warnung beide Zahlen
 * und den Weg nach vorn — und sie geht einmal je Prozess hinaus, nicht einmal
 * je Lesevorgang, sonst ertränkt sie das Protokoll.
 *
 * ## Die Mitte wird nicht gefragt
 *
 * Gesucht war eine Zusicherung („jedes Token gilt mindestens N Minuten"), kein
 * Zeitpunkt. Ein mitgeschickter Ablauf wäre beim Lesen aus dem
 * Zwischenspeicher schon veraltet — genau deshalb setzt die PAIRS-Regel in
 * {@see \Peppermint\Mailbox\Stores\MergedAccountStore} `oauth_token_expires_at`
 * bewusst auf null. Die Zusicherung ist eine Konstante und steht hier; sie
 * SPIEGELT die der Mitte, sie liest sie nicht. Ändert sich dort die
 * Mindestlaufzeit, muss sie hier nachgezogen werden — ein Paket, das AI Brain
 * zur Laufzeit befragen müsste, liefe ohne Bridge nicht mehr.
 */
final class Zwischenspeicherfenster
{
    /**
     * Was die Mitte zusichert, in Sekunden.
     *
     * Spiegelt `MailAccountTokenService::MINDESTLAUFZEIT_MINUTEN` (20) in AI
     * Brain. Dort steht auch, warum es zwanzig sind und nicht fünf oder
     * sechzig.
     */
    public const MINDESTLAUFZEIT_SEKUNDEN = 20 * 60;

    /**
     * Was zwischen „aus dem Speicher geholt" und „angemeldet" passen muss.
     *
     * Der ungünstigste Fall ist der Zugriff kurz vor Ablauf des Fensters: Das
     * Token hat dann nur noch `MINDESTLAUFZEIT - Fenster` übrig, und damit muss
     * eine ganze Postfach-Sitzung auskommen — Verbinden, Ordner, mehrere
     * Abrufe. Fünf Minuten sind der Abstand, mit dem die Mindestlaufzeit in der
     * Mitte bemessen wurde („Zwanzig Minuten decken das Fenster mit fünf
     * Minuten Luft ab"); er steht hier noch einmal, weil er hier wirkt.
     */
    public const SITZUNGSLUFT_SEKUNDEN = 5 * 60;

    /** Einmal je Prozess, nicht einmal je Lesevorgang. */
    private static bool $gemeldet = false;

    /**
     * Die längste Dauer, die ein Kontenstand gehalten werden darf.
     */
    public static function deckel(): int
    {
        return self::MINDESTLAUFZEIT_SEKUNDEN - self::SITZUNGSLUFT_SEKUNDEN;
    }

    /**
     * Die eingestellte Dauer — oder der Deckel, wenn sie darüber liegt.
     */
    public static function gedeckelt(int $sekunden): int
    {
        if ($sekunden <= self::deckel()) {
            return $sekunden;
        }

        self::melden($sekunden);

        return self::deckel();
    }

    /**
     * Lässt die Warnung noch einmal heraus.
     *
     * Nur für Tests. Ohne das wäre der erste Test, der den Deckel auslöst, der
     * einzige, der die Warnung sehen kann — und jeder weitere prüfte dann
     * stillschweigend nichts.
     */
    public static function erneutMelden(): void
    {
        self::$gemeldet = false;
    }

    private static function melden(int $sekunden): void
    {
        if (self::$gemeldet) {
            return;
        }

        self::$gemeldet = true;

        Log::warning(sprintf(
            'mailbox.cache_ttl steht auf %d s, gerechnet wird mit %d s. '
            .'Ein Zugriffstoken aus der Mitte gilt garantiert nur %d s; was länger '
            .'zwischengespeichert wird, ist beim Anmelden tot und kostet pro Fenster '
            .'einen sichtbaren Fehlversuch. Wer länger halten will, erhöht zuerst '
            .'MailAccountTokenService::MINDESTLAUFZEIT_MINUTEN in AI Brain und danach '
            .'Zwischenspeicherfenster::MINDESTLAUFZEIT_SEKUNDEN hier.',
            $sekunden,
            self::deckel(),
            self::MINDESTLAUFZEIT_SEKUNDEN,
        ));
    }
}
