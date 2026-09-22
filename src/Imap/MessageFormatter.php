<?php

namespace Peppermint\Mailbox\Imap;

use Peppermint\Mailbox\Support\Betreff;

use DirectoryTree\ImapEngine\Message;
use Illuminate\Support\Str;
use Peppermint\Mailbox\Content\CidReplacer;

/**
 * Turns an IMAP message into rows the rest of the world can use.
 *
 * ## The one class that knows what IMAP is
 *
 * Everything else in this package works on plain arrays, which is what makes
 * it shareable. This class is the border post: it is the only place that takes
 * a `DirectoryTree\ImapEngine\Message` and it exists so the products do not
 * each write the same extraction.
 *
 * `directorytree/imapengine` is a **suggest**, not a require. Installing this
 * package for its settings and rules must not drag an IMAP client along — and
 * a product that reads mail has the library anyway.
 *
 * ## Two shapes, and why the list one is not just the full one truncated
 *
 * `summary()` is what a list needs and nothing more, because a mailbox list
 * fetches hundreds of them; `full()` pulls bodies and attachments, which is a
 * different cost entirely. Merging them would make every list view pay for
 * attachment downloads.
 *
 * No labels: a message without a subject gets `null`. What a reader sees
 * instead is the product's decision, in the product's language.
 */
class MessageFormatter
{
    public function __construct(
        private readonly CidReplacer $cids = new CidReplacer,
    ) {}

    /**
     * For a list view: headers and a short preview.
     *
     * @return array<string, mixed>
     */
    public function summary(Message $message): array
    {
        $from = $message->from();

        return [
            'uid' => $message->uid(),
            'message_id' => $message->messageId(),
            'subject' => Betreff::lesbar($message->subject()),
            'from_address' => $from?->email() ?? '',
            'from_name' => $from?->name() ?? '',
            'date' => $message->date()?->toIso8601String(),
            'has_attachments' => $message->hasAttachments(),
            'attachment_count' => $message->attachmentCount(),
            'is_read' => $message->isSeen(),
            'is_flagged' => $message->isFlagged(),
            'preview' => Str::limit($message->text() ?? strip_tags($message->html() ?? ''), 100),
            // The threading headers travel along: they are loaded anyway when
            // listing, and without them a list cannot be grouped into
            // conversations afterwards without a second round trip.
            'in_reply_to' => $this->header($message, 'in-reply-to'),
            'references' => $this->header($message, 'references'),
        ];
    }

    /**
     * For reading one message: recipients, bodies, attachments.
     *
     * Inline images are resolved into the body right here. A reader that gets
     * the body and the attachments separately has to reinvent this, and
     * reinventing it is where the broken-image bugs come from.
     *
     * @return array<string, mixed>
     */
    public function full(Message $message): array
    {
        $from = $message->from();
        $attachments = [];
        $inline = [];

        foreach ($message->attachments() as $index => $attachment) {
            $contentId = $attachment->contentId();
            $disposition = $attachment->contentDisposition();

            $isInline = self::isInline($contentId, $disposition);

            if ($isInline && $contentId && Str::startsWith($attachment->contentType() ?? '', 'image/')) {
                $inline[$contentId] = 'data:'.$attachment->contentType().';base64,'.base64_encode($attachment->contents());

                continue;
            }

            $attachments[] = [
                'index' => $index,
                'filename' => $attachment->filename() ?? 'attachment',
                'mime_type' => $attachment->contentType(),
                'size' => Str::length($attachment->contents()),
            ];
        }

        $html = $message->html();

        if ($html && $inline !== []) {
            $html = $this->cids->replace($html, $inline);
        }

        return [
            'uid' => $message->uid(),
            'message_id' => $message->messageId(),
            'subject' => Betreff::lesbar($message->subject()),
            'from_address' => $from?->email() ?? '',
            'from_name' => $from?->name() ?? '',
            'to' => $this->addresses($message->to()),
            'cc' => $this->addresses($message->cc()),
            'date' => $message->date()?->toIso8601String(),
            'body_html' => $html,
            'body_text' => $message->text(),
            'attachments' => $attachments,
            'is_read' => $message->isSeen(),
            'is_flagged' => $message->isFlagged(),
            'in_reply_to' => $this->header($message, 'in-reply-to'),
            'references' => $this->header($message, 'references'),
        ];
    }

    /**
     * Does this part belong in the body rather than in the attachment list?
     *
     * An image with a Content-ID belongs in the body unless it is explicitly
     * marked as an attachment — some clients set neither disposition, and
     * treating those as attachments leaves a hole in the text where the picture
     * should be.
     *
     * Spelled once because {@see full()} and {@see attachmentAt()} MUST agree:
     * What is attachment number two on screen has to be attachment number two
     * when someone files it, or they file the wrong thing.
     */
    /**
     * Is this part an image that belongs IN the body?
     *
     * The narrower rule, and the one that decides what a forward carries: an
     * inline image is already embedded in `body_html`, so attaching it again
     * would duplicate it. An inline PDF is a file someone attached, whatever
     * the disposition says — it travels.
     */
    public static function isEmbeddedImage(?string $contentId, ?string $disposition, string $contentType): bool
    {
        return self::isInline($contentId, $disposition) && Str::startsWith($contentType, 'image/');
    }

    /**
     * The same message, but for keeping: body untouched, every file separate.
     *
     * @return array<string, mixed>
     */
    public function verbatim(Message $message): array
    {
        $from = $message->from();
        $files = [];

        foreach ($message->attachments() as $index => $attachment) {
            $contentId = $attachment->contentId();
            // EINMAL lesen: Der Inhalt kommt aus einem Strom, der nach dem
            // ersten Zugriff leer ist. Zweimal zu fragen schrieb die Datei
            // richtig und die Groesse daneben als 0 (Bug #557 im Manager).
            $contents = $attachment->contents();

            $files[] = [
                'index' => $index,
                'filename' => $attachment->filename() ?? 'attachment',
                'mime_type' => $attachment->contentType(),
                'extension' => $attachment->extension(),
                'contents' => $contents,
                'size' => Str::length($contents),
                'content_id' => $contentId,
                'inline' => self::isInline($contentId, $attachment->contentDisposition()),
            ];
        }

        return [
            'uid' => $message->uid(),
            'message_id' => $message->messageId(),
            'subject' => Betreff::lesbar($message->subject()),
            'from_address' => $from?->email() ?? '',
            'from_name' => $from?->name(),
            'to' => $this->addresses($message->to()),
            'cc' => $this->addresses($message->cc()),
            'date' => $message->date()?->toIso8601String(),
            // Roh, mit cid: — genau der Unterschied zu full().
            'body_html' => $message->html(),
            'body_text' => $message->text(),
            'files' => $files,
            'is_read' => $message->isSeen(),
            'is_flagged' => $message->isFlagged(),
            'in_reply_to' => $this->header($message, 'in-reply-to'),
            'references' => $this->header($message, 'references'),
        ];
    }

    private static function isInline(?string $contentId, ?string $disposition): bool
    {
        return $disposition === 'inline' || ($contentId && $disposition !== 'attachment');
    }

    /**
     * One attachment with its bytes, addressed by the same index {@see full()}
     * hands out.
     *
     * That index is the position in the message's own part list — NOT the
     * position in the filtered list. Inline images keep their number even
     * though they never appear on screen, so counting the visible ones here
     * would drift apart from the view as soon as a mail contains a logo.
     *
     * @return array{filename: string, mime_type: string, contents: string}|null
     */
    public function attachmentAt(mixed $message, int $index): ?array
    {
        foreach ($message->attachments() as $i => $attachment) {
            if ((int) $i !== $index) {
                continue;
            }

            if (self::isInline($attachment->contentId(), $attachment->contentDisposition())) {
                // Die Stelle gibt es, sie gehoert aber in den Rumpf. Wer sie
                // anfordert, meint etwas anderes — nichts zurueckgeben ist
                // ehrlicher als ein Logo auszuliefern.
                return null;
            }

            return [
                'filename' => $attachment->filename() ?? 'attachment',
                'mime_type' => (string) $attachment->contentType(),
                'contents' => (string) $attachment->contents(),
            ];
        }

        return null;
    }

    /**
     * @param  iterable<mixed>  $addresses
     * @return array<int, array{email: string|null, name: string|null}>
     */
    private function addresses(iterable $addresses): array
    {
        $result = [];

        foreach ($addresses as $address) {
            $result[] = ['email' => $address->email(), 'name' => $address->name()];
        }

        return $result;
    }

    private function header(Message $message, string $name): ?string
    {
        return $message->header($name)?->getValue();
    }
}
