<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Peppermint\Mailbox\Contracts\AccountStore;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Stores\LocalAccountStore;

/**
 * Shared mailboxes — a team address several people work in.
 *
 * The failure this guards against is a quiet one: filtering on the owner alone
 * looks correct, returns rows, and leaves out every team address. Nobody sees
 * an error; somebody just cannot find the mailbox they had yesterday.
 */
uses(RefreshDatabase::class);

it('gives a person their own mailboxes and the open shared ones', function () {
    $eigenes = MailAccount::create(['email' => 'anna@example.test', 'user_id' => 1]);
    $fremdes = MailAccount::create(['email' => 'bert@example.test', 'user_id' => 2]);
    $geteilt = MailAccount::create(['email' => 'info@example.test', 'user_id' => null]);

    $sichtbar = (new LocalAccountStore)->all(1)->pluck('id');

    expect($sichtbar)->toContain($eigenes->id)
        ->toContain($geteilt->id)
        ->not->toContain($fremdes->id);
});

it('limits a shared mailbox to the people on its access list', function () {
    $geteilt = MailAccount::create(['email' => 'buchhaltung@example.test', 'user_id' => null]);

    DB::table(config('mailbox.sharing.table'))->insert([
        'mail_account_id' => $geteilt->id,
        'user_id' => 1,
    ]);

    expect((new LocalAccountStore)->all(1)->pluck('id'))->toContain($geteilt->id)
        // The point of the list: person 2 is not on it.
        ->and((new LocalAccountStore)->all(2)->pluck('id'))->not->toContain($geteilt->id);
});

it('keeps a shared mailbox open while nobody has been assigned', function () {
    // Switching access lists on must not take mailboxes away from people
    // overnight. An empty list means "not restricted", not "nobody".
    $geteilt = MailAccount::create(['email' => 'info@example.test', 'user_id' => null]);

    expect((new LocalAccountStore)->all(99)->pluck('id'))->toContain($geteilt->id);
});

it('works in products without any access list', function () {
    // `sharing.table => null` is the open-source default case: shared
    // mailboxes exist, restricted ones do not.
    config()->set('mailbox.sharing.table', null);

    $geteilt = MailAccount::create(['email' => 'info@example.test', 'user_id' => null]);

    expect(MailAccount::sharingTable())->toBeNull()
        ->and((new LocalAccountStore)->all(7)->pluck('id'))->toContain($geteilt->id);
});

it('does not query an access list that is configured but not migrated', function () {
    // The dangerous shape: the config names a table, the migration has not run
    // yet. A query against it would fail harder than the missing feature does.
    config()->set('mailbox.sharing.table', 'gibt_es_nicht');

    MailAccount::create(['email' => 'info@example.test', 'user_id' => null]);

    expect(MailAccount::sharingTable())->toBeNull()
        ->and(fn () => (new LocalAccountStore)->all(1))->not->toThrow(Exception::class);
});

it('tells a shared mailbox from a private one', function () {
    expect(MailAccount::create(['email' => 'info@example.test', 'user_id' => null])->isShared())->toBeTrue()
        ->and(MailAccount::create(['email' => 'anna@example.test', 'user_id' => 1])->isShared())->toBeFalse();
});

it('carries the shared ones through the central store as well', function () {
    // The central store hands out what Brain sends; a mailbox without an owner
    // must survive that trip as a shared one.
    $konto = MailAccount::fromRemote(['id' => 3, 'email' => 'info@example.test', 'user_id' => null]);

    expect($konto->isShared())->toBeTrue()
        ->and(app(AccountStore::class))->toBeInstanceOf(LocalAccountStore::class);
});
