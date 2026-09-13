<?php

namespace Peppermint\Mailbox\Health;

/**
 * How a mailbox is doing.
 *
 * ## Why these five, and why they are core
 *
 * The package already ships `health_status` as a core column — and until now
 * it stored a value it had no vocabulary for. Two products filled that column
 * independently and arrived at the same words: `ok`, `warning`, `critical`,
 * and in the one that polls a throttling provider, `throttled` and `timeout`.
 *
 * That is the same evidence as the five health columns themselves: two
 * implementations converging without knowing about each other.
 *
 * ## What is NOT decided here
 *
 * How many failing checks in a row it takes before somebody is told. That
 * number belongs to the product, because it follows from the cadence: a probe
 * every two minutes needs three confirmations to be worth a message, one every
 * fifteen does not. A shared "3" would be wrong in both places.
 */
enum HealthStatus: string
{
    case Ok = 'ok';

    /** Reachable and authenticated, but slow enough to notice. */
    case Warning = 'warning';

    /** Unreachable, or the credentials no longer work. */
    case Critical = 'critical';

    /**
     * The provider is rate-limiting us.
     *
     * Deliberately its own state and NOT an alarm: throttling is expected, it
     * passes, and it says nothing about the mailbox. Treating it as a failure
     * produces alerts that train people to ignore alerts.
     */
    case Throttled = 'throttled';

    /**
     * The probe ran out of time.
     *
     * Separate from {@see Critical} because a timeout satisfies "not
     * authenticated" without saying anything about the credentials — reporting
     * it as critical sends people to check a password that is fine.
     */
    case Timeout = 'timeout';

    /**
     * The status a probe amounts to.
     *
     * The order is the point: throttling and timeouts are ruled out before the
     * red verdict, because both would otherwise look like a broken login.
     *
     * `$slow` is the product's slowness verdict — see {@see HealthRules}, which
     * offers the two rules that exist in practice.
     */
    public static function fromProbe(bool $reachable, bool $authOk, bool $throttled = false, bool $timedOut = false, bool $slow = false): self
    {
        if ($throttled) {
            return self::Throttled;
        }

        if ($timedOut) {
            return self::Timeout;
        }

        if (! $reachable || ! $authOk) {
            return self::Critical;
        }

        return $slow ? self::Warning : self::Ok;
    }

    /** Is this a state somebody should hear about? */
    public function isAlarming(): bool
    {
        return $this === self::Critical || $this === self::Warning;
    }

    /** Did the mailbox answer at all? */
    public function reached(): bool
    {
        return $this !== self::Critical;
    }
}
