<?php

use Peppermint\Mailbox\Contracts\Mailbox;
use Peppermint\Mailbox\Imap\MailboxClient;
use Peppermint\Mailbox\Jmap\JmapClient;
use Peppermint\Mailbox\Mailboxes;
use Peppermint\Mailbox\Models\MailAccount;

/**
 * The one place that chooses a transport (#5782).
 */
function kontoMit(array $werte = []): MailAccount
{
    return MailAccount::fromRemote(array_merge([
        'id' => 1, 'email' => 'post@example.test', 'username' => null, 'password' => 'geheim',
        'imap_host' => 'mail.example.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        'auth_type' => 'password', 'oauth_access_token' => null, 'oauth_token_expires_at' => null,
    ], $werte));
}

it('gibt fuer ein JMAP-Konto den JMAP-Transport', function () {
    expect(Mailboxes::for(kontoMit(['protocol' => 'jmap'])))->toBeInstanceOf(JmapClient::class);
});

it('gibt fuer alles andere IMAP', function () {
    expect(Mailboxes::for(kontoMit(['protocol' => 'imap'])))->toBeInstanceOf(MailboxClient::class);
});

it('bleibt bei IMAP, wenn gar nichts dasteht', function () {
    // Der Bestand: Konten ohne Angabe sind die Mehrheit und bleiben, wo sie
    // sind. Ein Produkt, das die Spalte nie migriert hat, merkt von alldem
    // nichts.
    expect(Mailboxes::for(kontoMit()))->toBeInstanceOf(MailboxClient::class);
});

it('gibt in beiden Faellen dasselbe Versprechen heraus', function () {
    // Der Sinn der Fabrik: Der Aufrufer haelt einen Mailbox-Vertrag in der
    // Hand und nicht zwei verschiedene Klassen mit aehnlichen Methoden.
    expect(Mailboxes::for(kontoMit(['protocol' => 'jmap'])))->toBeInstanceOf(Mailbox::class)
        ->and(Mailboxes::for(kontoMit()))->toBeInstanceOf(Mailbox::class);
});
