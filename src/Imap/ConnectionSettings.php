<?php

namespace Peppermint\Mailbox\Imap;

use Peppermint\Mailbox\Models\MailAccount;

/**
 * The settings one IMAP connection is made from.
 *
 * Kept apart from the connecting itself so the decision can be tested without
 * a network: which credential goes in, and which authentication the server is
 * told to expect.
 *
 * ## Password and OAuth differ in more than the secret
 *
 * With a password, the user name is whatever the provider wants — often, but
 * not always, the address. With OAuth there is no user name of its own: the
 * address IS the identity, and the access token takes the password's place
 * while `authentication` tells the server to expect a bearer token rather than
 * a password. Sending an access token as a password gets a plain login refusal
 * with nothing in it that points at OAuth.
 */
class ConnectionSettings
{
    /**
     * @param  array{host: string, port: int, encryption: string, username: string, password: string, authentication?: string, validate_cert: bool, timeout: int}  $values
     */
    private function __construct(public readonly array $values) {}

    /**
     * Builds the settings for an account.
     *
     * Expects any OAuth refresh to have happened already — see
     * {@see \Peppermint\Mailbox\Contracts\TokenRefresher}.
     */
    public static function for(MailAccount $account, int $timeout = 120): self
    {
        $common = [
            'host' => (string) $account->field('host'),
            'port' => (int) $account->field('port'),
            'encryption' => (string) $account->field('encryption'),
            'validate_cert' => true,
            'timeout' => $timeout,
        ];

        if ($account->usesOAuth()) {
            return new self($common + [
                // No user name of its own: with OAuth the address is the identity.
                'username' => (string) $account->field('email'),
                'password' => (string) $account->field('oauth_access_token'),
                'authentication' => 'oauth',
            ]);
        }

        return new self($common + [
            'username' => (string) ($account->field('username') ?: $account->field('email')),
            'password' => (string) $account->field('password'),
        ]);
    }
}
