<?php

use Peppermint\Mailbox\Health\HealthRules;
use Peppermint\Mailbox\Health\HealthStatus;

/**
 * The health vocabulary, lifted from two products that invented it separately
 * (#5488) — same words, same 8-second threshold, no shared code.
 */
it('rules out throttling before the red verdict', function () {
    // Throttling satisfies "not authenticated" while saying nothing about the
    // mailbox. Reported as critical it sends people to check a password that
    // is fine — and trains them to ignore the next alert.
    expect(HealthStatus::fromProbe(reachable: true, authOk: false, throttled: true))
        ->toBe(HealthStatus::Throttled);
});

it('rules out a timeout before the red verdict', function () {
    expect(HealthStatus::fromProbe(reachable: false, authOk: false, timedOut: true))
        ->toBe(HealthStatus::Timeout);
});

it('calls an unreachable or rejected mailbox critical', function () {
    expect(HealthStatus::fromProbe(reachable: false, authOk: false))->toBe(HealthStatus::Critical)
        ->and(HealthStatus::fromProbe(reachable: true, authOk: false))->toBe(HealthStatus::Critical);
});

it('separates slow from healthy', function () {
    expect(HealthStatus::fromProbe(reachable: true, authOk: true, slow: true))->toBe(HealthStatus::Warning)
        ->and(HealthStatus::fromProbe(reachable: true, authOk: true))->toBe(HealthStatus::Ok);
});

it('says which states are worth telling somebody about', function () {
    // Throttling is expected and passes. An alert for it is noise.
    expect(HealthStatus::Throttled->isAlarming())->toBeFalse()
        ->and(HealthStatus::Ok->isAlarming())->toBeFalse()
        ->and(HealthStatus::Critical->isAlarming())->toBeTrue()
        ->and(HealthStatus::Warning->isAlarming())->toBeTrue();
});

it('keeps the stored strings both products already use', function () {
    // The column exists in live databases. A different spelling here would
    // quietly invalidate every stored row.
    expect(HealthStatus::Ok->value)->toBe('ok')
        ->and(HealthStatus::Warning->value)->toBe('warning')
        ->and(HealthStatus::Critical->value)->toBe('critical')
        ->and(HealthStatus::Throttled->value)->toBe('throttled')
        ->and(HealthStatus::Timeout->value)->toBe('timeout');
});

it('measures slowness against a fixed ceiling', function () {
    expect(HealthRules::exceedsCeiling(9000))->toBeTrue()
        ->and(HealthRules::exceedsCeiling(7000))->toBeFalse()
        ->and(HealthRules::exceedsCeiling(null))->toBeFalse();
});

it('stays quiet about a spike that is fast in absolute terms', function () {
    // Three times a 200 ms baseline is 600 ms. Nobody wants to hear about it.
    expect(HealthRules::isSpike(600, 200))->toBeFalse();
});

it('reports a mailbox that got much slower than it usually is', function () {
    expect(HealthRules::isSpike(20000, 2000))->toBeTrue()
        ->and(HealthRules::isSpike(9000, 5000))->toBeFalse();
});

it('treats a very slow first probe as a spike even without a baseline', function () {
    // Otherwise the first thirty-second answer of a new mailbox is silently
    // fine, and the baseline it builds is a broken one.
    expect(HealthRules::isSpike(30000, null))->toBeTrue()
        ->and(HealthRules::isSpike(30000, 0))->toBeTrue();
});

it('takes the median and not the average of past latencies', function () {
    // One thirty-second outlier would drag an average up far enough to hide
    // the next three spikes.
    expect(HealthRules::median([100, 200, 300, 400, 30000]))->toBe(300)
        ->and(HealthRules::median([100, 300]))->toBe(200)
        ->and(HealthRules::median([]))->toBeNull();
});
