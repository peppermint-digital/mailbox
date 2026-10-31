<?php

namespace Peppermint\Mailbox\Schreiben;

use Illuminate\Support\Facades\Log;
use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Der Weg zum Brain ueber `peppermint/ai-brain-bridge` — die Vorgabe.
 *
 * Drei der vier Produkte tragen die Bruecke ohnehin, und fuer sie aendert
 * sich durch {@see Kanalstrecke} nichts: Wer {@see Kanalklient} ohne Strecke
 * baut, bekommt diese hier. Der Inhalt stand bis zum 31.10.2026 als zwei
 * private Methoden im Kanalklient; er ist Zeile fuer Zeile derselbe.
 *
 * Herausgezogen wurde er nicht, weil er schlecht war, sondern weil er die
 * einzige Antwort auf eine Frage war, die zwei Antworten hat — siehe
 * {@see Kanalstrecke}.
 */
class BrueckenStrecke implements Kanalstrecke
{
    /** Der Kanal, den `thread()` nimmt: die Kennung reicht, der Name nicht. */
    private const EGAL = '_';

    /**
     * Ist die Bruecke ueberhaupt eingerichtet?
     *
     * Die Klasse zu HABEN heisst nicht, sie benutzen zu koennen: AI Brain
     * selbst traegt das Paket, ohne es einzurichten — es ist die Nabe.
     */
    public function eingerichtet(): bool
    {
        return class_exists(AiBrain::class) && ! empty(config('ai-brain-bridge.oauth.client_id'));
    }

    /** @param array<string, mixed> $nutzlast */
    public function hineinschicken(string $kanal, array $nutzlast): int
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
    public function nachsehen(int $chatId): array
    {
        try {
            $strang = AiBrain::channel(self::EGAL)->thread($chatId);
        } catch (\Throwable $e) {
            Log::error('AI-Brain-Kanal: Nachsehen fehlgeschlagen', ['fehler' => $e->getMessage()]);

            throw new \RuntimeException('Der Stand der Anfrage konnte nicht abgerufen werden.');
        }

        return [
            'status' => (string) ($strang['status'] ?? Kanalklient::RUNNING),
            'result' => $strang['result'] ?? null,
            'error' => is_string($strang['error'] ?? null) ? $strang['error'] : null,
        ];
    }
}
