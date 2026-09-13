<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Peppermint\Mailbox\Models\MailAccount;
use Peppermint\Mailbox\Stores\LocalAccountStore;

uses(RefreshDatabase::class);

it('creates the core table and finds accounts in it', function () {
    MailAccount::create(['email' => 'office@example.test', 'user_id' => 3]);
    MailAccount::create(['email' => 'other@example.test', 'user_id' => 9]);

    $store = new LocalAccountStore;

    expect($store->all()->pluck('email')->all())->toHaveCount(2)
        ->and($store->all(3)->pluck('email')->all())->toBe(['office@example.test']);
});

it('stores secrets encrypted', function () {
    // What is checked is the row, not the return value: an `encrypted` cast
    // somebody removes later does not show up on the model — in clear text in
    // the column it does.
    $account = MailAccount::create(['email' => 'office@example.test', 'password' => 'secret123']);

    $raw = DB::table('mail_accounts')->where('id', $account->id)->value('password');

    expect($raw)->not->toBe('secret123')
        ->and($account->fresh()->password)->toBe('secret123');
});

it('hides credentials when handing an account out', function () {
    // An account travels into Inertia props and MCP responses. Without $hidden
    // the password would sit in the page source.
    $account = MailAccount::create([
        'email' => 'office@example.test',
        'password' => 'secret123',
        'oauth_refresh_token' => 'rt-123',
    ]);

    expect(array_keys($account->toArray()))
        ->not->toContain('password')
        ->not->toContain('oauth_refresh_token');
});
