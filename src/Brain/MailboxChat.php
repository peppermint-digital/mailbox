<?php

namespace Peppermint\Mailbox\Brain;

use Illuminate\Support\Facades\Log;

/**
 * The conversation with the mailbox agent in AI Brain.
 *
 * ## Why this is not left to each product
 *
 * There is nothing product-specific about it. The product knows who may open
 * which mailbox; everything after that — which fields come back, what a missing
 * one means, when an empty answer is an outage and when it is simply an empty
 * conversation — is the same everywhere, and each of those answers cost a bug:
 *
 * - An older Brain sends no `activity`. Reading it as a missing key rather than
 *   as an error is the difference between "the assistant looks like it did
 *   before" and "the assistant is broken".
 * - `mailbox_not_found` and `no_channel_for_mailbox` are nameable reasons and
 *   belong in the log. Without them "assistant empty" cannot be told apart from
 *   "AI Brain gone" — and those need opposite responses.
 *
 * ## Never null
 *
 * {@see verlauf()} always answers a complete shape. A product that has to
 * distinguish "null" from "empty" writes that fallback itself, differently each
 * time, and one of those times forgets a key the frontend reads.
 */
class MailboxChat
{
    /**
     * @param  callable(string $email): ?array  $holen  The `mailbox-chat-tool`
     *         call. Returns the decoded answer, or null when Brain could not be
     *         reached — null is a state, not an error to be thrown.
     * @param  callable(int $chatId, string $content): ?array  $senden  Posts a
     *         reply into the chat. Same contract.
     */
    public function __construct(
        private $holen,
        private $senden,
    ) {}

    /**
     * The conversation as the browser needs it — always complete.
     *
     * @return array{chat_id: ?int, channel: string, status: ?string, messages: array<int, mixed>, activity: array<int, mixed>}
     */
    public function verlauf(string $email): array
    {
        $leer = ['chat_id' => null, 'channel' => '', 'status' => null, 'messages' => [], 'activity' => []];

        try {
            $r = ($this->holen)($email);
        } catch (\Throwable $e) {
            Log::error('AI Brain mailbox chat exception', ['email' => $email, 'error' => $e->getMessage()]);

            return $leer;
        }

        if ($r === null || ($r['ok'] ?? false) !== true) {
            Log::info('AI Brain mailbox chat unavailable', [
                'email' => $email,
                'reason' => $r['error'] ?? 'nicht erreichbar',
            ]);

            return $leer;
        }

        return [
            'chat_id' => ((int) ($r['chat_id'] ?? 0)) ?: null,
            'channel' => (string) ($r['channel'] ?? ''),
            'status' => ($r['status'] ?? null) !== null ? (string) $r['status'] : null,
            'messages' => (array) ($r['messages'] ?? []),
            // The agent's last working steps. If the field is missing, an older
            // Brain is speaking — then it stays empty and the assistant looks
            // like it did before, instead of breaking.
            'activity' => (array) ($r['activity'] ?? []),
        ];
    }

    /**
     * Send a message, and say what to answer the browser.
     *
     * @return array{ok: bool, error: ?string, status: int} `status` is the HTTP
     *         code the product should answer with — the distinction between "no
     *         chat for this mailbox" (404) and "Brain refused" (502) is what
     *         makes the difference visible in the browser's network tab.
     */
    public function senden(string $email, string $content): array
    {
        $chat = $this->verlauf($email);

        if (! $chat['chat_id']) {
            return ['ok' => false, 'error' => 'Für dieses Postfach gibt es keinen Chat.', 'status' => 404];
        }

        try {
            $r = ($this->senden)($chat['chat_id'], $content);
        } catch (\Throwable $e) {
            Log::error('AI Brain channel reply exception', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'AI Brain ist nicht erreichbar.', 'status' => 502];
        }

        if ($r === null) {
            return ['ok' => false, 'error' => 'AI Brain ist nicht erreichbar.', 'status' => 502];
        }

        // An answer without `ok` comes from an endpoint that only reports
        // failure — treating that as a failure would make every successful send
        // look broken.
        if (($r['ok'] ?? true) === true) {
            return ['ok' => true, 'error' => null, 'status' => 202];
        }

        $grund = trim((string) ($r['error'] ?? '')) ?: 'Unbekannter Grund.';
        Log::warning('AI Brain channel reply rejected', ['chat_id' => $chat['chat_id'], 'reason' => $grund]);

        return ['ok' => false, 'error' => $grund, 'status' => 502];
    }
}
