<?php

namespace Peppermint\Mailbox\Jmap;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Peppermint\Mailbox\Content\CidReplacer;

/**
 * A JMAP message, in the rows the rest of the package already speaks.
 *
 * The shape is not negotiable and not derived from JMAP: it is what the IMAP
 * MessageFormatter produces, because every consumer above — threading, paging, deduplication, the React views — was
 * written against those keys. A transport that returns its own idea of a row
 * is a second product, not a second transport. TransportContractTest holds the
 * two side by side.
 *
 * ## What JMAP gives for free, and why that changes nothing here
 *
 * The server can thread, sort and count. It still has to answer in these keys,
 * so that a product does not have to ask which transport it is on before it
 * reads a field.
 *
 * ## Inline images
 *
 * Same rule as IMAP: an image with a Content-ID belongs in the body, not in
 * the attachment list — and it keeps its position in that list anyway, so that
 * attachment number two on screen is attachment number two when someone files
 * it.
 */
class JmapMessageFormatter
{
    public function __construct(
        private readonly CidReplacer $cids = new CidReplacer,
    ) {}

    /**
     * For a list view: headers and a short preview.
     *
     * @param  array<string, mixed>  $email
     * @return array<string, mixed>
     */
    public function summary(array $email): array
    {
        $from = $email['from'][0] ?? null;

        return [
            'uid' => $email['id'] ?? null,
            'message_id' => $this->firstMessageId($email['messageId'] ?? null),
            'subject' => $email['subject'] ?? null,
            'from_address' => $from['email'] ?? '',
            'from_name' => $from['name'] ?? '',
            'date' => $this->date($email),
            'has_attachments' => (bool) ($email['hasAttachment'] ?? false),
            'attachment_count' => count($this->visibleAttachments($email)),
            'is_read' => $this->hasKeyword($email, '$seen'),
            'is_flagged' => $this->hasKeyword($email, '$flagged'),
            // JMAP delivers a preview the server already cut. Cutting it again
            // to the same length is what keeps the two transports comparable:
            // a list must not change its line length when a mailbox moves.
            'preview' => Str::limit((string) ($email['preview'] ?? ''), 100),
            'in_reply_to' => $this->firstMessageId($email['inReplyTo'] ?? null),
            'references' => $this->joined($email['references'] ?? null),
        ];
    }

    /**
     * For reading one message: recipients, bodies, attachments.
     *
     * @param  array<string, mixed>  $email
     * @param  callable(string, string, string): ?string  $blob  fetches an attachment's bytes
     * @return array<string, mixed>
     */
    public function full(array $email, callable $blob): array
    {
        $attachments = [];
        $inline = [];

        foreach ($this->parts($email) as $index => $part) {
            $contentId = $part['cid'] ?? null;
            $type = (string) ($part['type'] ?? '');

            if (self::isInline($contentId, $part['disposition'] ?? null)) {
                if ($contentId && Str::startsWith($type, 'image/')) {
                    $bytes = $blob((string) ($part['blobId'] ?? ''), (string) ($part['name'] ?? 'inline'), $type);

                    if ($bytes !== null) {
                        $inline[$contentId] = 'data:'.$type.';base64,'.base64_encode($bytes);
                    }
                }

                continue;
            }

            $attachments[] = [
                'index' => $index,
                'filename' => $part['name'] ?? 'attachment',
                'mime_type' => $part['type'] ?? null,
                'size' => (int) ($part['size'] ?? 0),
            ];
        }

        $html = $this->body($email, 'htmlBody');

        if ($html && $inline !== []) {
            $html = $this->cids->replace($html, $inline);
        }

        $from = $email['from'][0] ?? null;

        return [
            'uid' => $email['id'] ?? null,
            'message_id' => $this->firstMessageId($email['messageId'] ?? null),
            'subject' => $email['subject'] ?? null,
            'from_address' => $from['email'] ?? '',
            'from_name' => $from['name'] ?? '',
            'to' => $this->addresses($email['to'] ?? []),
            'cc' => $this->addresses($email['cc'] ?? []),
            'date' => $this->date($email),
            'body_html' => $html,
            'body_text' => $this->body($email, 'textBody'),
            'attachments' => $attachments,
            'is_read' => $this->hasKeyword($email, '$seen'),
            'is_flagged' => $this->hasKeyword($email, '$flagged'),
            'in_reply_to' => $this->firstMessageId($email['inReplyTo'] ?? null),
            'references' => $this->joined($email['references'] ?? null),
        ];
    }

    /**
     * The part at this position, or null when it is one of the inline ones.
     *
     * Deliberately the same arithmetic as the view: the index counts every
     * part the message has, inline images included. Counting only the visible
     * ones here would drift apart from the list as soon as a mail carries a
     * logo — and then someone files the logo instead of the invoice.
     *
     * @param  array<string, mixed>  $email
     * @return array<string, mixed>|null
     */
    public function partAt(array $email, int $index): ?array
    {
        foreach ($this->parts($email) as $i => $part) {
            if ($i !== $index) {
                continue;
            }

            if (self::isInline($part['cid'] ?? null, $part['disposition'] ?? null)) {
                return null;
            }

            return $part;
        }

        return null;
    }

    /**
     * Same rule as the IMAP side, spelled the same way on purpose.
     */
    private static function isInline(?string $contentId, ?string $disposition): bool
    {
        return $disposition === 'inline' || ($contentId && $disposition !== 'attachment');
    }

    /**
     * Every part that is not the body, in document order.
     *
     * @param  array<string, mixed>  $email
     * @return list<array<string, mixed>>
     */
    private function parts(array $email): array
    {
        return array_values($email['attachments'] ?? []);
    }

    /** @param array<string, mixed> $email */
    private function visibleAttachments(array $email): array
    {
        return array_filter(
            $this->parts($email),
            fn (array $part): bool => ! self::isInline($part['cid'] ?? null, $part['disposition'] ?? null),
        );
    }

    /**
     * The body text of one kind, assembled from the values the server sent.
     *
     * Null rather than an empty string when there is none: a reader has to be
     * able to tell "no HTML part" from "an HTML part that is empty".
     *
     * @param  array<string, mixed>  $email
     */
    private function body(array $email, string $kind): ?string
    {
        $stuecke = [];

        foreach ($email[$kind] ?? [] as $part) {
            $partId = $part['partId'] ?? null;
            $wert = $partId !== null ? ($email['bodyValues'][$partId]['value'] ?? null) : null;

            if (is_string($wert)) {
                $stuecke[] = $wert;
            }
        }

        return $stuecke === [] ? null : implode("\n", $stuecke);
    }

    /** @param array<string, mixed> $email */
    private function date(array $email): ?string
    {
        $wert = $email['receivedAt'] ?? $email['sentAt'] ?? null;

        return is_string($wert) ? Carbon::parse($wert)->toIso8601String() : null;
    }

    /** @param array<string, mixed> $email */
    private function hasKeyword(array $email, string $keyword): bool
    {
        return (bool) ($email['keywords'][$keyword] ?? false);
    }

    /**
     * A message id in the spelling the rest of the world uses: with brackets.
     *
     * JMAP hands ids over stripped (`a@x`), a mail header carries them wrapped
     * (`<a@x>`), and the IMAP transport passes the header through untouched.
     * Both spellings work for threading — ThreadKey normalises — but not
     * everything normalises:
     *
     * - `MessagePage::dedupe()` keys on the raw value, so one mailbox read
     *   twice in two spellings would show every sent mail twice.
     * - Products store this value. The Manager files who is working on a mail
     *   under its message id; had this gone out stripped, switching a mailbox
     *   to JMAP would have quietly detached every existing assignment.
     *
     * None of that fails loudly, which is why the contract test compares the
     * two transports field by field — it caught exactly this.
     *
     * @param  list<string>|string|null  $value
     */
    private function firstMessageId(array|string|null $value): ?string
    {
        $id = is_string($value) ? $value : ($value[0] ?? null);

        if ($id === null || trim($id) === '') {
            return null;
        }

        return '<'.trim($id, " \t<>").'>';
    }

    /**
     * References travel as one header value, because that is what the
     * threading code parses.
     *
     * @param  list<string>|string|null  $value
     */
    private function joined(array|string|null $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value === null || $value === []) {
            return null;
        }

        return implode(' ', array_map(fn (string $id): string => '<'.trim($id, '<>').'>', $value));
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     * @return array<int, array{email: string|null, name: string|null}>
     */
    private function addresses(array $addresses): array
    {
        return array_map(
            fn (array $a): array => ['email' => $a['email'] ?? null, 'name' => $a['name'] ?? null],
            array_values($addresses),
        );
    }
}
