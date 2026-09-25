<?php

namespace Peppermint\Mailbox\Stores;

use Peppermint\Mailbox\Contracts\Verlaufsspeicher;
use Peppermint\Mailbox\Models\MailMessage;

/**
 * Der Verlauf aus den eigenen Tabellen — fuer das System, das archiviert.
 */
class LokalerVerlaufsspeicher implements Verlaufsspeicher
{
    public function verfuegbar(int $account, string $thread): bool
    {
        if ($thread === '') {
            return false;
        }

        /*
         * Erkannt an `is_root`: Beim Erfassen wird ausgerechnet, ob der
         * Kettenschluessel auf die Nachricht selbst zeigt — dann hat sie
         * keinen Vorgaenger und ist der Anfang.
         *
         * Beim Lesen zu vergleichen waere der naheliegende Weg und der
         * falsche: Der Schluessel ist normalisiert (`abc@x`), die Message-ID
         * nicht (`<abc@x>`).
         */
        $hatAnfang = MailMessage::query()
            ->where('email_account_id', $account)
            ->where('thread_key', $thread)
            ->where('is_root', true)
            ->exists();

        if (! $hatAnfang) {
            return false;
        }

        /*
         * Und mindestens eine Nachricht, die noch da ist.
         *
         * Eine Kette, aus der ALLES entfernt wurde, hat nichts mehr zu
         * erzaehlen — sie bestuende nur noch aus Grabsteinen. Wer den
         * Umschalter trotzdem angeboten bekommt, klickt auf einen Verlauf,
         * der ihm bloss mitteilt, dass hier einmal etwas war.
         *
         * Umgekehrt genuegt EINE gebliebene Nachricht: Dann ist der Verlauf
         * echt, und die Luecken darin gehoeren dazu (siehe `verlauf()`).
         */
        return MailMessage::query()
            ->where('email_account_id', $account)
            ->where('thread_key', $thread)
            ->whereNull('purged_at')
            ->exists();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function verlauf(int $account, string $thread): array
    {
        return MailMessage::query()
            ->where('email_account_id', $account)
            ->where('thread_key', $thread)
            ->with(['body', 'aktuelleOrte'])
            ->orderBy('sent_at')
            ->get()
            ->map(fn (MailMessage $n): array => $n->purged_at !== null
                ? $this->grabstein($n)
                : $this->eintrag($n))
            ->values()
            ->all();
    }

    /**
     * Eine entfernte Nachricht behaelt ihren Platz in der Kette.
     *
     * ## Warum sie nicht einfach wegfaellt
     *
     * Entfernt ist der INHALT, nicht die Tatsache. Wer den Verlauf liest,
     * braucht die Stelle: Ohne sie beziehen sich die folgenden Antworten auf
     * etwas, das es nie gegeben zu haben scheint — „worauf antwortet das?"
     * ist dann nicht mehr zu beantworten, und der Verlauf luegt durch
     * Auslassung.
     *
     * ## Warum nicht als leere Blase
     *
     * Genau das war sie bis v0.120.0, und das war der schlimmere Fall: Die
     * Anzeige sagte „Kein Text — vermutlich nur ein Anhang" und behauptete
     * damit etwas Falsches ueber eine Nachricht, die es sehr wohl gab. Eine
     * stille Falschaussage ist schlechter als eine sichtbare Luecke.
     *
     * ## Was hier drinsteht
     *
     * Zeitpunkt und Urheber der Entfernung, sonst nichts. Der Grund steht im
     * Protokoll — dort gehoert er hin, nicht in eine Blase, die jeder sieht,
     * der das Postfach lesen darf.
     *
     * @return array<string, mixed>
     */
    private function grabstein(MailMessage $n): array
    {
        return [
            'id' => $n->id,
            'message_id' => '',
            'from' => ['email' => null, 'name' => null],
            // Bleibt: Er haelt die Stelle in der Reihenfolge.
            'sent_at' => $n->sent_at?->toIso8601String(),
            'subject' => null,
            'content' => '',
            'original' => null,
            'refined_by' => null,
            'quote' => null,
            'signature' => null,
            'footer' => null,
            'has_attachments' => false,
            'attachment_count' => 0,
            // Kein Weg zum Original: Der Sinn der Entfernung ist, dass es
            // von hier aus keinen gibt.
            'in_mailbox' => false,
            'folder' => null,
            'uid' => null,
            'purged_at' => $n->purged_at?->toIso8601String(),
            'purged_by' => $n->purged_by,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eintrag(MailMessage $n): array
    {
        return [
            'id' => $n->id,
            'message_id' => $n->message_id,
            'from' => ['email' => $n->from_email, 'name' => $n->from_name],
            'sent_at' => $n->sent_at?->toIso8601String(),
            'subject' => $n->subject,
            // Die veredelte Fassung, wenn es eine gibt.
            'content' => $n->body?->lesbar() ?? '',
            // Und was wirklich dastand — nur mitgeschickt, wenn ein Modell
            // mitgeschrieben hat.
            'original' => $n->body?->istVeredelt() ? $n->body->content : null,
            'refined_by' => $n->body?->refined_by,
            // Was die Aufbereitung beiseitegelegt hat, reist mit. Greift
            // eine Regel daneben, sieht man es und klappt auf.
            'quote' => $n->body?->quote,
            'signature' => $n->body?->signature,
            'footer' => $n->body?->footer,
            'has_attachments' => (bool) $n->has_attachments,
            'attachment_count' => (int) $n->attachment_count,
            'in_mailbox' => $n->imPostfach(),
            'folder' => $n->aktuelleOrte->first()?->folder,
            'uid' => $n->aktuelleOrte->first()?->uid,
            'purged_at' => null,
            'purged_by' => null,
        ];
    }
}
