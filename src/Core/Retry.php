<?php

declare(strict_types=1);

namespace Oblodai\Core;

use Oblodai\Exception\OblodaiException;
use Oblodai\Exception\TransportException;

/**
 * Retry policy. Two questions decide every retry:
 *
 * 1. Can it succeed? — the core's `retryable` flag (authoritative when the core wrote the envelope),
 *    or a transient status for answers that carry no envelope.
 * 2. Is repeating safe? — only for read-only routes and for writes the core deduplicates by
 *    Idempotency-Key. A write the core does not deduplicate is never re-sent once it MAY have
 *    reached the core: a transport error or a proxy 503 after the request left the socket could
 *    mean the payout already happened.
 *
 * An envelope error on an unsafe write is still retried when `retryable` — the core answered, so it
 * did not perform the operation (429/503/frozen/maturing all fail before any effect).
 * `Retry-After` always wins over the computed backoff; otherwise exponential backoff with jitter.
 */
final class Retry
{
    public function __construct(
        /** Maximum number of retries after the first attempt. */
        public readonly int $maxRetries = 2,
        /** Base delay for the first retry, ms. */
        public readonly int $baseDelayMs = 250,
        /** Upper bound for a computed (non-Retry-After) delay, ms. */
        public readonly int $maxDelayMs = 4000,
        /** Upper bound honored for a server-provided Retry-After, ms. */
        public readonly int $maxRetryAfterMs = 30000,
    ) {
    }

    /** @param array{maxRetries?: int, baseDelayMs?: int, maxDelayMs?: int, maxRetryAfterMs?: int} $overrides */
    public function with(array $overrides): self
    {
        return new self(
            $overrides['maxRetries'] ?? $this->maxRetries,
            $overrides['baseDelayMs'] ?? $this->baseDelayMs,
            $overrides['maxDelayMs'] ?? $this->maxDelayMs,
            $overrides['maxRetryAfterMs'] ?? $this->maxRetryAfterMs,
        );
    }

    /** @param int $attempt 0 for the first retry decision (i.e. after attempt #1 failed) */
    public function shouldRetry(OblodaiException $error, int $attempt, bool $safeToRepeat): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }
        if (!$error->retryable) {
            return false;
        }
        if ($error instanceof TransportException) {
            return $safeToRepeat;
        }
        // No core envelope: something in front of the core answered; the core may have done the work.
        if ($error->synthetic) {
            return $safeToRepeat;
        }

        return true;
    }

    /**
     * Delay before the next attempt, in ms. `$random` is injectable for deterministic tests.
     *
     * @param (callable(): float)|null $random
     */
    public function delayMs(OblodaiException $error, int $attempt, ?callable $random = null): int
    {
        if ($error->retryAfter !== null && $error->retryAfter > 0) {
            return min($error->retryAfter * 1000, $this->maxRetryAfterMs);
        }
        $exp = min($this->maxDelayMs, $this->baseDelayMs * (2 ** $attempt));
        $rolled = $random === null ? mt_rand() / mt_getrandmax() : $random();
        $roll = is_numeric($rolled) ? (float) $rolled : 0.0;

        // Full jitter with a floor so a burst of retries never lands in the same instant.
        return (int) max(intdiv($exp, 4), (int) floor($roll * $exp));
    }
}
