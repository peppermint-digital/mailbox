<?php

namespace Peppermint\Mailbox\Threading;

use Illuminate\Support\Str;

/**
 * Which conversation does a message belong to?
 *
 * The rules come from RFC 5322: `References` lists the chain starting at the
 * oldest message, `In-Reply-To` names the direct predecessor. A chain is
 * identified by the message-id of its root — so every message in the same
 * conversation carries the same value without anyone having to assign one.
 *
 * ## Why this is the package's business and the lookup is not
 *
 * Everything here is a rule about headers: same input, same answer, in every
 * product. What is NOT here is the part that asks "do we already know this
 * message" — that means a table, and the table belongs to the product. See
 * {@see resolveAgainst()} for where the line runs.
 *
 * Every rule is static and free of storage, so it can be checked without IMAP
 * and without a mailbox.
 */
class ThreadKey
{
    /**
     * Message-ids arrive with angle brackets, without them, and with
     * whitespace around them. Without normalising in ONE place, the same
     * conversation falls apart into two.
     */
    public static function normalize(?string $messageId): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $id = trim($messageId);
        $id = trim($id, '<>');
        $id = trim($id);

        return $id === '' ? null : $id;
    }

    /**
     * Every message-id in a `References` header, oldest first.
     *
     * @return list<string>
     */
    public static function parseReferences(?string $references): array
    {
        if ($references === null || trim($references) === '') {
            return [];
        }

        // In the wild the separator is whitespace, a comma, or both.
        $parts = preg_split('/[\s,]+/', trim($references)) ?: [];

        $ids = [];

        foreach ($parts as $part) {
            $id = self::normalize($part);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The chain id from the headers alone — no storage involved.
     *
     * Order: root from `References`, else the direct predecessor from
     * `In-Reply-To`, else this message's own id (in which case this message
     * starts the chain).
     */
    public static function fromHeaders(?string $messageId, ?string $inReplyTo = null, ?string $references = null): ?string
    {
        $referenced = self::parseReferences($references);

        $root = $referenced !== []
            ? $referenced[0]
            : (self::normalize($inReplyTo) ?? self::normalize($messageId));

        return self::fit($root);
    }

    /**
     * A column holding this stays indexable at 255 characters. Longer root ids
     * exist — rare, but they exist — and would be truncated silently on save,
     * giving two different conversations the same value. So instead of cutting:
     * a short form that stays unique.
     */
    public static function fit(?string $threadId): ?string
    {
        if ($threadId === null) {
            return null;
        }

        return mb_strlen($threadId) <= 255
            ? $threadId
            : 'h:'.sha1($threadId);
    }

    /**
     * Stored message-ids exist in both spellings, so a lookup has to ask for
     * both.
     *
     * @return list<string>
     */
    public static function bracketVariants(string $messageId): array
    {
        $bare = self::normalize($messageId) ?? $messageId;

        return [$bare, '<'.$bare.'>'];
    }

    /**
     * Like {@see fromHeaders()}, but reconciled with what the product already
     * stored.
     *
     * Needed because some clients shorten the `References` chain: if C replies
     * to B and names only B instead of root A, the header rule alone would
     * start a second chain. If B is already known, its chain id wins — the
     * conversation grows together instead of falling apart.
     *
     * The lookup is passed IN, it is not done here. What "already known" means
     * is a query against the product's own message table, and a package that
     * guessed that table would only fit the product it was extracted from.
     *
     * @param  callable(list<string>): ?string  $known  Receives both spellings
     *                                                  of the candidate and
     *                                                  returns a stored chain
     *                                                  id, or null.
     */
    public static function resolveAgainst(
        callable $known,
        ?string $messageId,
        ?string $inReplyTo = null,
        ?string $references = null,
    ): ?string {
        $candidate = self::fromHeaders($messageId, $inReplyTo, $references);

        if ($candidate === null) {
            return null;
        }

        return $known(self::bracketVariants($candidate)) ?: $candidate;
    }

    /**
     * When no header yields anything, the normalised subject is the last
     * resort. Deliberately prefixed, so a chain id shows how certain it is.
     */
    public static function fallbackFromSubject(?string $subject): ?string
    {
        if ($subject === null || trim($subject) === '') {
            return null;
        }

        $normalized = Str::lower(trim($subject));

        // Strip repeated "Re:"/"AW:"/"Fwd:" — nested ones too.
        do {
            $before = $normalized;
            $normalized = preg_replace('/^\s*(re|aw|fwd|fw|wg)\s*(\[\d+\])?\s*:\s*/i', '', $normalized) ?? $normalized;
        } while ($normalized !== $before);

        $normalized = trim($normalized);

        return $normalized === '' ? null : self::fit('subject:'.$normalized);
    }
}
