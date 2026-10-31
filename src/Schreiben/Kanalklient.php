<?php

namespace Peppermint\Mailbox\Schreiben;

/**
 * Eine Anfrage an einen AI-Brain-Kanal, bis sie fertig ist.
 *
 * ## Warum das Paket das selbst kann
 *
 * Der Schreibassistent laeuft nicht ueber einen MCP-Aufruf, sondern ueber
 * einen KANAL: hineinschicken, pollen bis `done`, Ergebnis entpacken. Die
 * Bruecke kann Kanaele (`AiBrain::channel()->message()/thread()`), aber das
 * Warten darauf lag im Projekt-Manager, in `AiBrainChannelService` — 241
 * Zeilen, von denen drei Produkte nichts hatten.
 *
 * Hier steht die Teilmenge, die ein Dienst am Editor braucht. Bewusst OHNE
 * `getMessages`/`postMessage`: Das ist die Rueckfrage-Strecke fuer einen
 * Menschen im Gespraech, und die hat mit „korrigiere diesen Absatz" nichts zu
 * tun. Eine kleinere Flaeche ist hier ehrlicher als eine vollstaendige.
 *
 * ## Das Pollen ist kein Schoenheitsfehler
 *
 * Es blockiert die Anfrage, und das ist der Grund fuer die Frist: Ein Kanal,
 * der nicht antwortet, darf den Verfasser nicht ewig haengen lassen. Nach
 * Ablauf gibt es eine Absage mit Grund — der Mensch kann es noch einmal
 * versuchen, und sein Text steht noch da.
 *
 * ## Was hier steht und was nicht mehr
 *
 * Seit dem 31.10.2026 kennt diese Klasse den WEG nicht mehr, nur noch das
 * Warten: Frist, Takt, welcher Status ein Ende ist und welcher nicht. Der
 * Transport steckt hinter {@see Kanalstrecke}, und ohne Angabe ist er
 * {@see BrueckenStrecke} — fuer die drei Produkte mit Bruecke aendert sich
 * damit nichts.
 *
 * Der Grund steht in {@see Kanalstrecke}: AI Brain ist die Nabe und braucht
 * keinen Netzaufruf zu sich selbst. Ohne diese Naht haette es Pollen, Frist,
 * `needs_input` und das Entpacken ein zweites Mal gebaut.
 */
class Kanalklient
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const NEEDS_INPUT = 'needs_input';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /**
     * @param  Kanalstrecke  $strecke  Wie die Anfrage hinkommt. Steht ABSICHTLICH
     *                                 hinten: Ein Produkt, das heute
     *                                 `new Kanalklient(30, 1)` baut, um seine
     *                                 Frist zu setzen, soll davon nichts merken.
     */
    public function __construct(
        private readonly int $fristSekunden = 60,
        private readonly int $taktSekunden = 2,
        private readonly Kanalstrecke $strecke = new BrueckenStrecke,
    ) {}

    /**
     * Ist die BRUECKE eingerichtet?
     *
     * Bleibt statisch und bleibt die Bruecken-Frage: Ein Produkt ruft sie,
     * um zu entscheiden, ob es ueberhaupt bindet, und das tut es, bevor es
     * einen Klienten hat.
     *
     * Wer eine eigene Strecke mitbringt, fragt sie NICHT — fuer den ist
     * {@see nutzbar()} die richtige Frage. Diese hier wuerde `false` sagen und
     * damit einen Assistenten verstecken, den es gibt.
     */
    public static function eingerichtet(): bool
    {
        return (new BrueckenStrecke)->eingerichtet();
    }

    /**
     * Ist DIESER Klient benutzbar — ueber seine eigene Strecke?
     *
     * Die Fassung von {@see eingerichtet()}, die nach dem Weg fragt, den
     * dieser Klient wirklich nimmt. Fuer die Vorgabe antworten beide gleich;
     * fuer eine eigene Strecke antwortet nur diese richtig.
     */
    public function nutzbar(): bool
    {
        return $this->strecke->eingerichtet();
    }

    /** Noch nicht fertig — weiter warten. `needs_input` ist dabei kein Ende. */
    public static function wartet(string $status): bool
    {
        return in_array($status, [self::QUEUED, self::RUNNING, self::NEEDS_INPUT], true);
    }

    /**
     * Hineinschicken, warten, Ergebnis herausgeben.
     *
     * @param  array<string, mixed>  $nutzlast
     *
     * @throws \RuntimeException mit einem Satz, der dem Absender etwas sagt
     */
    public function fragen(string $kanal, array $nutzlast): mixed
    {
        $chatId = $this->strecke->hineinschicken($kanal, $nutzlast);
        $frist = time() + max(5, $this->fristSekunden);

        do {
            $stand = $this->strecke->nachsehen($chatId);

            if ($stand['status'] === self::DONE) {
                return $stand['result'];
            }

            if ($stand['status'] === self::FAILED) {
                throw new \RuntimeException($stand['error'] ?: 'Die Anfrage konnte nicht verarbeitet werden.');
            }

            if (time() >= $frist) {
                break;
            }

            sleep(max(1, $this->taktSekunden));
        } while (true);

        throw new \RuntimeException('Zeitüberschreitung — die Anfrage dauert länger als erwartet.');
    }

    /**
     * Das `result`-Feld als Liste.
     *
     * Es kommt mal als Array, mal als JSON-Zeichenkette — und manchmal in
     * einem Markdown-Block, weil ein Sprachmodell am anderen Ende geantwortet
     * hat. Die drei Faelle hier zu behandeln ist kein Schoenheitsfehler,
     * sondern der Unterschied zwischen „antwortet nicht" und „antwortet".
     *
     * @return array<string, mixed>|null
     */
    public static function entpacken(mixed $ergebnis): ?array
    {
        if (is_array($ergebnis)) {
            return $ergebnis;
        }

        if (! is_string($ergebnis) || $ergebnis === '') {
            return null;
        }

        $sauber = trim($ergebnis);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $sauber, $treffer)) {
            $sauber = $treffer[1];
        }

        $entpackt = json_decode($sauber, true);

        return is_array($entpackt) ? $entpackt : null;
    }
}
