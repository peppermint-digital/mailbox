<?php

namespace Peppermint\Mailbox\Schreiben;

use Illuminate\Support\Facades\Log;
use Peppermint\AiBrainBridge\Facades\AiBrain;

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
 */
class Kanalklient
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const NEEDS_INPUT = 'needs_input';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** Der Kanal, den `thread()` nimmt: die Kennung reicht, der Name nicht. */
    private const EGAL = '_';

    public function __construct(
        private readonly int $fristSekunden = 60,
        private readonly int $taktSekunden = 2,
    ) {}

    /**
     * Ist die Bruecke ueberhaupt eingerichtet?
     *
     * Die Klasse zu HABEN heisst nicht, sie benutzen zu koennen: AI Brain
     * selbst traegt das Paket, ohne es einzurichten — es ist die Nabe.
     */
    public static function eingerichtet(): bool
    {
        return class_exists(AiBrain::class) && ! empty(config('ai-brain-bridge.oauth.client_id'));
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
        $chatId = $this->hineinschicken($kanal, $nutzlast);
        $frist = time() + max(5, $this->fristSekunden);

        do {
            $stand = $this->nachsehen($chatId);

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

    /** @param array<string, mixed> $nutzlast */
    private function hineinschicken(string $kanal, array $nutzlast): int
    {
        try {
            $antwort = AiBrain::channel($kanal)->message($nutzlast, ref: $kanal.':'.time());
        } catch (\Throwable $e) {
            Log::error('AI-Brain-Kanal nicht erreichbar', ['kanal' => $kanal, 'fehler' => $e->getMessage()]);

            throw new \RuntimeException('Verbindung zur KI fehlgeschlagen.');
        }

        if (($antwort['ok'] ?? false) !== true || ! is_numeric($antwort['chat_id'] ?? null)) {
            Log::warning('AI-Brain-Kanal hat die Anfrage nicht angenommen', ['kanal' => $kanal, 'antwort' => $antwort]);

            throw new \RuntimeException('Verbindung zur KI fehlgeschlagen.');
        }

        return (int) $antwort['chat_id'];
    }

    /** @return array{status: string, result: mixed, error: ?string} */
    private function nachsehen(int $chatId): array
    {
        try {
            $strang = AiBrain::channel(self::EGAL)->thread($chatId);
        } catch (\Throwable $e) {
            Log::error('AI-Brain-Kanal: Nachsehen fehlgeschlagen', ['fehler' => $e->getMessage()]);

            throw new \RuntimeException('Der Stand der Anfrage konnte nicht abgerufen werden.');
        }

        return [
            'status' => (string) ($strang['status'] ?? self::RUNNING),
            'result' => $strang['result'] ?? null,
            'error' => is_string($strang['error'] ?? null) ? $strang['error'] : null,
        ];
    }
}
