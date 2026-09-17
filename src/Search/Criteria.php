<?php

namespace Peppermint\Mailbox\Search;

use DateTimeImmutable;

/**
 * What someone is looking for, in one place.
 *
 * Both transports have to understand the same search, and neither may invent
 * its own vocabulary: IMAP asks with `TEXT`/`FROM`/`SUBJECT`/`SINCE`/`UNSEEN`,
 * JMAP with a filter object. A product that had to know which words its
 * mailbox understands would be back to knowing the protocol.
 *
 * ## Empty is not a search
 *
 * A criteria object with nothing in it would match everything — and over IMAP
 * that means fetching an entire mailbox, folder by folder. {@see isEmpty()}
 * exists so the transports can refuse it instead of taking the server down
 * with a blank query someone typed by accident.
 *
 * ## `since` is a date, not a string
 *
 * It arrives as a string from a request and leaves as a `DateTimeImmutable`,
 * parsed exactly once. Parsing at the point of use means every transport
 * repeats it, and the second one parses it slightly differently.
 */
final class Criteria
{
    private function __construct(
        public readonly ?string $text = null,
        public readonly ?string $from = null,
        public readonly ?string $subject = null,
        public readonly ?DateTimeImmutable $since = null,
        public readonly bool $unseen = false,
    ) {}

    /**
     * @param array{
     *     text?: string|null,
     *     from?: string|null,
     *     subject?: string|null,
     *     since?: string|\DateTimeInterface|null,
     *     unseen?: bool|string|int|null
     * } $criteria
     */
    public static function fromArray(array $criteria): self
    {
        $seit = $criteria['since'] ?? null;

        if (is_string($seit) && trim($seit) !== '') {
            $seit = new DateTimeImmutable($seit);
        } elseif ($seit instanceof \DateTimeInterface) {
            $seit = DateTimeImmutable::createFromInterface($seit);
        } else {
            $seit = null;
        }

        return new self(
            text: self::text($criteria['text'] ?? null),
            from: self::text($criteria['from'] ?? null),
            subject: self::text($criteria['subject'] ?? null),
            since: $seit,
            unseen: filter_var($criteria['unseen'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * Nothing to search for — every mailbox would match.
     */
    public function isEmpty(): bool
    {
        return $this->text === null
            && $this->from === null
            && $this->subject === null
            && $this->since === null
            && ! $this->unseen;
    }

    /**
     * The JMAP filter for this search.
     *
     * `notKeyword` rather than a negated `keyword`: JMAP has no NOT, and
     * "unread" is the absence of `$seen`, not the presence of something else.
     *
     * @return array<string, mixed>
     */
    public function toJmapFilter(): array
    {
        $filter = [];

        if ($this->text !== null) {
            $filter['text'] = $this->text;
        }

        if ($this->from !== null) {
            $filter['from'] = $this->from;
        }

        if ($this->subject !== null) {
            $filter['subject'] = $this->subject;
        }

        if ($this->since !== null) {
            // UTC with a Z, which is what the standard asks for — a local
            // offset is accepted by some servers and silently ignored by others.
            $filter['after'] = $this->since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        if ($this->unseen) {
            $filter['notKeyword'] = '$seen';
        }

        return $filter;
    }

    /**
     * Applies this search to an ImapEngine query.
     *
     * Deliberately untyped: the query object comes from the library, and what
     * it has to be able to do is defined by the calls below, not by a name.
     */
    public function applyToImapQuery(mixed $query): void
    {
        if ($this->text !== null) {
            // TEXT looks in headers AND body — the only IMAP term that does.
            $query->text($this->text);
        }

        if ($this->from !== null) {
            $query->from($this->from);
        }

        if ($this->subject !== null) {
            $query->subject($this->subject);
        }

        if ($this->since !== null) {
            $query->since($this->since);
        }

        if ($this->unseen) {
            $query->unseen();
        }
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
