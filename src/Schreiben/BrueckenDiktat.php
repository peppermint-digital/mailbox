<?php

namespace Peppermint\Mailbox\Schreiben;

use Illuminate\Support\Facades\Http;
use Peppermint\AiBrainBridge\Auth\OAuthTokenProvider;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\Mailbox\Contracts\Diktat;
use RuntimeException;

/**
 * Die Spracheingabe ueber die Bruecke.
 *
 * Anders als der Schreibassistent laeuft sie NICHT ueber einen Kanal, sondern
 * ueber einen zustandslosen Endpunkt: `POST {brain}/api/v1/transcribe` reicht
 * die Aufnahme an ein selbst betriebenes Whisper durch und gibt nur den Text
 * zurueck. Kein Chat, kein Pollen, keine Ablage.
 *
 * Dass die beiden verschieden funktionieren, ist kein Zufall und sollte nicht
 * eingeebnet werden: Ein Kanal ist fuer etwas da, das dauern und rueckfragen
 * darf. Abtippen dauert und fragt nicht zurueck.
 *
 * ## Es wird nichts abgelegt, und das ist eine Zusage
 *
 * Weder hier noch drueben. Was gesprochen wurde, gehoert dem Menschen, der es
 * gesprochen hat.
 */
class BrueckenDiktat implements Diktat
{
    public function __construct(
        private readonly OAuthTokenProvider $tokens,
        private readonly string $sprache = 'de',
        private readonly int $fristSekunden = 60,
    ) {}

    public static function verfuegbar(): bool
    {
        return Kanalklient::eingerichtet() && ! empty(config('ai-brain-bridge.base_url'));
    }

    public function abtippen(string $pfad, string $mimeTyp): string
    {
        $inhalt = @file_get_contents($pfad);

        if ($inhalt === false || $inhalt === '') {
            throw new RuntimeException('Die Aufnahme konnte nicht gelesen werden.');
        }

        $basis = rtrim((string) config('ai-brain-bridge.base_url'), '/');

        try {
            $antwort = Http::withToken($this->tokens->token())
                ->withHeaders(AiBrain::actingUserHeaders())
                ->acceptJson()
                ->timeout($this->fristSekunden)
                ->attach('audio', $inhalt, 'aufnahme.'.$this->endung($mimeTyp))
                ->post("{$basis}/api/v1/transcribe", ['language' => $this->sprache]);
        } catch (\Throwable) {
            throw new RuntimeException('Verbindung zur Spracherkennung fehlgeschlagen.');
        }

        if (! $antwort->successful()) {
            throw new RuntimeException('Die Aufnahme konnte nicht abgetippt werden (HTTP '.$antwort->status().').');
        }

        $text = $antwort->json('text');

        if (! is_string($text) || trim($text) === '') {
            // Stille ist ein moegliches Ergebnis — aber kein brauchbares.
            // Besser eine Meldung als ein Cursor, der nicht weiterrueckt.
            throw new RuntimeException('Es war nichts zu verstehen.');
        }

        return trim($text);
    }

    /** Der Dateiname ist Beiwerk, die Endung hilft dem Modell beim Dekodieren. */
    private function endung(string $mimeTyp): string
    {
        return match (true) {
            str_contains($mimeTyp, 'ogg') => 'ogg',
            str_contains($mimeTyp, 'mp4') => 'm4a',
            str_contains($mimeTyp, 'mpeg') => 'mp3',
            default => 'webm',
        };
    }
}
