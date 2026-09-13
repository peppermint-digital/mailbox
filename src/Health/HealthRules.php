<?php

namespace Peppermint\Mailbox\Health;

/**
 * When is a mailbox slow?
 *
 * Two rules exist in practice, and they are not interchangeable — so the
 * package offers both instead of averaging them into one that fits neither.
 *
 * - {@see exceedsCeiling()}: slower than a fixed ceiling. Right where probes
 *   are frequent and a baseline is cheap to miss.
 * - {@see isSpike()}: slower than usual for THIS mailbox. Right where mailboxes
 *   differ so much that a single ceiling would either shout at the slow one or
 *   stay silent for the fast one.
 *
 * The default ceiling of 8 seconds is not a guess: two products picked exactly
 * that number independently, one as its warning threshold and one as the floor
 * below which a spike does not count.
 */
class HealthRules
{
    public const SLOW_CEILING_MS = 8000;

    public const SPIKE_FACTOR = 3.0;

    /** Slower than the fixed ceiling. */
    public static function exceedsCeiling(?int $latencyMs, int $ceilingMs = self::SLOW_CEILING_MS): bool
    {
        return $latencyMs !== null && $latencyMs > $ceilingMs;
    }

    /**
     * Slower than usual for this mailbox — by a factor, and only once it is
     * slow in absolute terms too.
     *
     * The floor is what keeps this quiet: three times a 200 ms baseline is
     * still 600 ms, and nobody wants to hear about that.
     */
    public static function isSpike(
        ?int $latencyMs,
        ?int $baselineMedianMs,
        float $factor = self::SPIKE_FACTOR,
        int $floorMs = self::SLOW_CEILING_MS,
    ): bool {
        if ($latencyMs === null || $latencyMs < $floorMs) {
            return false;
        }

        if ($baselineMedianMs === null || $baselineMedianMs <= 0) {
            // No baseline yet: fall back to the ceiling, so a first probe that
            // takes half a minute is not silently fine.
            return true;
        }

        return $latencyMs > $baselineMedianMs * $factor;
    }

    /**
     * The median of past latencies — the baseline {@see isSpike()} wants.
     *
     * Median and not average, because one thirty-second outlier would drag an
     * average up far enough to hide the next three.
     *
     * @param  list<int>  $latencies
     */
    public static function median(array $latencies): ?int
    {
        $values = array_values(array_filter($latencies, fn ($w) => is_int($w) && $w >= 0));

        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
