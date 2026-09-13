<?php

use Illuminate\Support\Facades\Cache;
use Peppermint\Mailbox\Stores\BrainAccountStore;

/**
 * The central store, and how it behaves when the central system is down.
 */
it('caches success only, never failure', function () {
    // This is the core of it: caching a failure would overwrite the last known
    // good state and turn a short outage into a long one.
    $responses = [
        ['data' => [['id' => 1, 'email' => 'office@example.test']]],
        null,
        null,
    ];

    $calls = 0;
    $store = new BrainAccountStore(function () use (&$responses, &$calls) {
        $calls++;

        return array_shift($responses);
    }, 900);

    expect($store->all()->pluck('email')->all())->toBe(['office@example.test']);

    // Second round: the central system has stopped answering — the cached
    // state carries on, and no call goes out at all.
    expect($store->all()->pluck('email')->all())->toBe(['office@example.test'])
        ->and($calls)->toBe(1);
});

it('returns nothing when there is neither a fresh nor a cached state', function () {
    // Do not throw: the screen still has to render. A mail browser that never
    // appears because a second system is down is worse than one showing
    // nothing.
    Cache::flush();

    $store = new BrainAccountStore(fn () => null, 900);

    expect($store->all())->toBeEmpty();
});

it('is not writable', function () {
    expect((new BrainAccountStore(fn () => null))->isWritable())->toBeFalse();
});

it('carries central accounts instead of storing them', function () {
    // `exists = false` is the brake against `save()` — otherwise the product
    // creates exactly the second truth the central store exists to prevent.
    $store = new BrainAccountStore(fn () => ['data' => [['id' => 7, 'email' => 'a@example.test']]]);

    $account = $store->find(7);

    expect($account)->not->toBeNull()
        ->and($account->exists)->toBeFalse();
});
