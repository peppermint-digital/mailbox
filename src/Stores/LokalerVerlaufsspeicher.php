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
        return MailMessage::query()
            ->where('email_account_id', $account)
            ->where('thread_key', $thread)
            ->where('is_root', true)
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
            ->map(fn (MailMessage $n): array => [
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
            ])
            ->values()
            ->all();
    }
}
