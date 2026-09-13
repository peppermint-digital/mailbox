<?php

use Illuminate\Support\Facades\Log;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Stores\LocalAccountStore;

/**
 * Which store gets built — and what happens when the setting lies.
 */
it('uses the local store out of the box', function () {
    expect(app(AccountStore::class))->toBeInstanceOf(LocalAccountStore::class)
        ->and(app(AccountStore::class)->isWritable())->toBeTrue();
});

it('falls back to local when the bridge is missing — and says so', function () {
    // The subject under test is not the fallback but the warning. Falling back
    // silently would mean somebody holds the mailboxes to be central while
    // they are local, and edits the wrong copy on the next password rotation.
    Log::spy();

    config()->set('mailbox.store', 'brain');
    app()->forgetInstance(AccountStore::class);

    expect(app(AccountStore::class))->toBeInstanceOf(LocalAccountStore::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'LOCALLY'))
        ->once();
});
