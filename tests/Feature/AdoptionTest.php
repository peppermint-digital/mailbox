<?php

use Peppermint\Mailbox\Models\MailAccount;

/**
 * Adoption of grown tables.
 *
 * Without it the package can only serve fresh installations — and then the
 * grown product builds its mail browser a second time, which is the very
 * reason this package exists.
 */
it('reads core fields through the product own column names', function () {
    config()->set('mailbox.columns', ['email' => 'email_address', 'imap_host' => 'host']);

    expect(MailAccount::column('email'))->toBe('email_address')
        ->and(MailAccount::column('imap_host'))->toBe('host')
        // Unmapped fields keep the package name.
        ->and(MailAccount::column('smtp_host'))->toBe('smtp_host');

    $account = MailAccount::fromRemote(['email_address' => 'office@example.test', 'host' => 'imap.example.test']);

    expect($account->field('email'))->toBe('office@example.test')
        ->and($account->field('imap_host'))->toBe('imap.example.test');
});

it('takes the table name from configuration', function () {
    expect((new MailAccount)->getTable())->toBe('mail_accounts');

    config()->set('mailbox.tables.accounts', 'email_accounts');

    expect((new MailAccount)->getTable())->toBe('email_accounts');
});
