<?php

use Peppermint\Mailbox\Models\MailAccount;

/**
 * A central account carries the package's field names, not the product's
 * column names.
 *
 * The bug this stands against was silent and expensive: a product with a
 * column map read central data through that same map, found nothing there,
 * and reported "differs" for EVERY field — which in a sync means: overwrite
 * everything.
 */
beforeEach(function () {
    config()->set('mailbox.columns', ['imap_host' => 'host', 'label' => 'name']);
});

it('reads a central account under the package names', function () {
    $central = MailAccount::fromRemote(['email' => 'a@x', 'imap_host' => 'imap.x', 'label' => 'Office']);

    expect($central->isRemote())->toBeTrue()
        ->and($central->field('imap_host'))->toBe('imap.x')
        ->and($central->field('label'))->toBe('Office');
});

it('still reads an own account through the map', function () {
    $own = new MailAccount(['email' => 'a@x']);
    $own->setAttribute('host', 'imap.x');
    $own->setAttribute('name', 'Office');

    expect($own->isRemote())->toBeFalse()
        ->and($own->field('imap_host'))->toBe('imap.x')
        ->and($own->field('label'))->toBe('Office');
});
