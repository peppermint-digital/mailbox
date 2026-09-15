<?php

namespace Peppermint\Mailbox\Imap;

use Peppermint\Mailbox\Exceptions\MissingAccessToken;
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
            // `imap_host` first: a mail account has an IMAP host AND an SMTP
            // host, and both this package's consumers as well as AI Brain name
            // them apart. A bare `host` is accepted for settings that do not.
            'host' => (string) ($account->field('imap_host') ?: $account->field('host')),
            'port' => (int) ($account->field('imap_port') ?: $account->field('port')),
            'encryption' => (string) ($account->field('imap_encryption') ?: $account->field('encryption')),
            'validate_cert' => true,
            'timeout' => $timeout,
        ];

        if ($account->usesOAuth()) {
            $token = (string) $account->field('oauth_access_token');

            // An empty token would be sent as an empty password, and the server
            // would answer with a plain login refusal — a message that says
            // nothing about OAuth and sends whoever reads it looking for a
            // wrong password that does not exist.
            //
            // A central store often keeps only what is needed to MINT an access
            // token (tenant, client id, secret, refresh token) and not the
            // short-lived token itself. Then a TokenRefresher has to run first.
            if ($token === '') {
                throw new MissingAccessToken(
                    'This mailbox signs in with OAuth but carries no access token. '
                    .'Its settings hold '.self::vorhandeneOAuthFelder($account).'. '
                    .'Pass a TokenRefresher to the client so one can be obtained before connecting.'
                );
            }

            return new self($common + [
                // No user name of its own: with OAuth the address is the identity.
                'username' => (string) $account->field('email'),
                'password' => $token,
                'authentication' => 'oauth',
            ]);
        }

        return new self($common + [
            'username' => (string) ($account->field('username') ?: $account->field('email')),
            'password' => (string) $account->field('password'),
        ]);
    }

    /** Names what the settings DO hold, so the gap is obvious in the message. */
    private static function vorhandeneOAuthFelder(MailAccount $account): string
    {
        $felder = [];

        foreach (['oauth_tenant_id', 'oauth_client_id', 'oauth_client_secret', 'oauth_refresh_token'] as $name) {
            if ($account->field($name)) {
                $felder[] = $name;
            }
        }

        return $felder === [] ? 'no OAuth fields at all' : implode(', ', $felder);
    }
}
