<?php

namespace Peppermint\Mailbox\Imap;

use Throwable;

/**
 * When a failed IMAP call is worth trying again, and how long to wait.
 *
 * ## Why this is a rule and not a detail
 *
 * An IMAP connection dies for two very different reasons, and they must not be
 * treated alike. A dropped socket, a timeout, a stream that went away — those
 * are the network, and the same call a second later usually works. A refused
 * login, a folder that does not exist, a malformed command — those are
 * answers, and repeating them wastes the person's time while pretending to
 * work.
 *
 * Retrying the second kind is worse than failing: it turns an error that would
 * have been reported in a second into half a minute of silence, and then
 * reports the same error anyway.
 *
 * ## The wait grows, and it is not the same for everyone
 *
 * Doubling (2s, 4s, 8s, 16s, 32s) gives a server that is briefly overwhelmed
 * room to recover. The jitter on top matters more than it looks: without it,
 * every client that lost the connection at the same moment comes back at the
 * same moment, and the server gets the same stampede it just survived.
 */
class RetryPolicy
{
    /**
     * Substrings that mark an error as "the line, not the answer".
     *
     * Matched case-insensitively against the message, because the wording
     * comes from a dozen layers — PHP streams, the IMAP library, the server.
     *
     * @var list<string>
     */
    public const TRANSIENT = [
        'eof',
        'connection',
        'closed',
        'reset',
        'broken pipe',
        'timed out',
        'timeout',
        'stream',
    ];

    public function __construct(
        /** How often to try again before giving up. */
        public readonly int $maxRetries = 5,
        /** Waits the given seconds. Injectable so tests do not actually wait. */
        private readonly ?\Closure $sleeper = null,
        /** Returns 0..1 for the jitter. Injectable so tests are deterministic. */
        private readonly ?\Closure $jitter = null,
    ) {}

    /**
     * Is this failure the line rather than the answer?
     */
    public function isRetryable(Throwable $error): bool
    {
        $message = mb_strtolower($error->getMessage());

        foreach (self::TRANSIENT as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How long to wait before attempt number `$attempt` (1-based).
     *
     * Exponential with jitter, so a server that dropped many clients at once
     * does not get them all back at once.
     */
    public function delayFor(int $attempt): float
    {
        $jitter = $this->jitter ? ($this->jitter)() : mt_rand(0, 1000) / 1000;

        return 2 ** $attempt + $jitter;
    }

    /**
     * Runs the call, trying again while the failures look like the line.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @param  null|callable(Throwable, int, int): void  $onRetry  told about each retry, for logging
     * @return T
     *
     * @throws Throwable the last failure, once giving up
     */
    public function run(callable $call, ?callable $onRetry = null): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $call();
            } catch (Throwable $error) {
                // Not the line, or nothing left to try: the caller gets the
                // real error, not a retry story.
                if (! $this->isRetryable($error) || $attempt >= $this->maxRetries) {
                    throw $error;
                }

                $attempt++;

                if ($onRetry) {
                    $onRetry($error, $attempt, $this->maxRetries);
                }

                $this->sleep($this->delayFor($attempt));
            }
        }
    }

    private function sleep(float $seconds): void
    {
        if ($this->sleeper) {
            ($this->sleeper)($seconds);

            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }
}
