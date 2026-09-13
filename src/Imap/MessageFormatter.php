<?php

namespace Peppermint\Mailbox\Imap;

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
            'subject' => $message->subject(),
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

            // An image with a Content-ID belongs in the body unless it is
            // explicitly marked as an attachment — some clients set neither
            // disposition, and treating those as attachments leaves a hole in
            // the text where the picture should be.
            $isInline = $disposition === 'inline' || ($contentId && $disposition !== 'attachment');

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
            'subject' => $message->subject(),
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
