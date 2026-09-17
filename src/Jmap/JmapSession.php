<?php

namespace Peppermint\Mailbox\Jmap;

use Peppermint\Mailbox\Models\MailAccount;
use RuntimeException;

/**
 * The session document — where a JMAP server says what it can and who you are.
 *
 * Every JMAP conversation starts here. The document answers three things no
 * client may guess: the address calls go to (`apiUrl`), the id of the account
 * whose mail this is (`accountId`), and the template for fetching a blob
 * (`downloadUrl`). Guessing any of them works against one server and breaks
 * against the next.
 *
 * ## Fetched once per client, not once per call
 *
 * The document changes when capabilities change, which is roughly never during
 * a request. Fetching it before every call would double the round trips and
 * undo the reason for using JMAP at all.
 *
 * ## The account id is not ours to invent
 *
 * `primaryAccounts['urn:ietf:params:jmap:mail']` names the account that holds
 * the mail. For a personal login that is the only account, but a login with
 * delegated access sees several — taking the first one from the list would
 * quietly read a colleague's mailbox.
 */
class JmapSession
{
    public const CORE = 'urn:ietf:params:jmap:core';

    public const MAIL = 'urn:ietf:params:jmap:mail';

    private function __construct(
        public readonly string $apiUrl,
        public readonly string $accountId,
        public readonly string $downloadUrl,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public static function fromDocument(array $document): self
    {
        $accountId = $document['primaryAccounts'][self::MAIL] ?? null;

        if (! is_string($accountId) || $accountId === '') {
            throw new RuntimeException(
                'This JMAP server offers no mail account for these credentials. '
                .'Without urn:ietf:params:jmap:mail in primaryAccounts there is nothing to read.'
            );
        }

        foreach (['apiUrl', 'downloadUrl'] as $pflicht) {
            if (! isset($document[$pflicht]) || ! is_string($document[$pflicht])) {
                throw new RuntimeException("The JMAP session document has no {$pflicht}.");
            }
        }

        return new self($document['apiUrl'], $accountId, $document['downloadUrl']);
    }

    /**
     * Where the session document of this account lives.
     *
     * @throws RuntimeException when the account has no host to ask
     */
    public static function urlFor(MailAccount $account): string
    {
        $url = $account->jmapSessionUrl();

        if ($url === null) {
            throw new RuntimeException(
                'This mailbox has no host, so there is no JMAP session document to fetch.'
            );
        }

        return $url;
    }

    /**
     * The address of one blob — an attachment's bytes.
     *
     * The server hands out a template rather than a URL, and the placeholders
     * are part of the contract: a client that builds the address itself breaks
     * the day a server puts the blob somewhere else.
     */
    public function blobUrl(string $blobId, string $name, string $type): string
    {
        return str_replace(
            ['{accountId}', '{blobId}', '{name}', '{type}'],
            [
                rawurlencode($this->accountId),
                rawurlencode($blobId),
                rawurlencode($name),
                rawurlencode($type),
            ],
            $this->downloadUrl,
        );
    }
}
