<?php

declare(strict_types=1);

namespace Oblodai\Core;

/**
 * Injectable clock for signing. The core rejects timestamps more than ±300 s from its own time;
 * a host with a drifting clock would get `merchant.bad_signature` on every call. The transport
 * learns the server's time from the `Date` header of a signature-failure response, re-signs once,
 * and keeps the offset only if that re-signed attempt got past authentication.
 *
 * The offset is shared by every call made through one client, and calls can interleave (Fibers,
 * Swoole, ReactPHP — anywhere a request can suspend at its socket). So it is never written blindly:
 * a call remembers the offset it SIGNED with ({@see Clock::stamp()}) and only ever installs or
 * reverts an offset when the shared value is still the one it saw ({@see Clock::correctIfUnchanged()}).
 * Two calls discovering the same skew therefore converge instead of undoing each other.
 */
class Clock
{
    /** Offsets beyond this are implausible drift and are ignored (a broken proxy `Date`). */
    public const MAX_PLAUSIBLE_OFFSET_SECONDS = 24 * 3600;

    private int $offsetSec = 0;

    /** @param (callable(): int)|null $source unix seconds; injectable for tests */
    public function __construct(private $source = null)
    {
    }

    /** Current unix time in seconds, including any learned offset. */
    public function now(): int
    {
        return $this->base() + $this->offsetSec;
    }

    /**
     * The timestamp to sign with, together with the offset that produced it — read as one step, so
     * a concurrent correction cannot land between the two and leave a call unable to tell which
     * offset its own signature carries.
     *
     * @return array{0: int, 1: int} unix seconds (offset applied), offset used
     */
    public function stamp(): array
    {
        $offset = $this->offsetSec;

        return [$this->base() + $offset, $offset];
    }

    /** Server-minus-local offset currently applied, seconds. */
    public function offset(): int
    {
        return $this->offsetSec;
    }

    /**
     * Measure the offset from a response `Date` header; null when absent, unparsable or implausible.
     */
    public function observeServerDate(?string $dateHeader): ?int
    {
        if ($dateHeader === null || trim($dateHeader) === '') {
            return null;
        }
        $serverTs = Util::parseHttpDate($dateHeader);
        if ($serverTs === null) {
            return null;
        }
        $offset = $serverTs - $this->base();

        return abs($offset) > self::MAX_PLAUSIBLE_OFFSET_SECONDS ? null : $offset;
    }

    public function correct(int $offsetSec): void
    {
        $this->offsetSec = $offsetSec;
    }

    /**
     * Compare-and-set: install `$offsetSec` only while the shared offset is still `$expected`.
     * Returns whether this call won. A caller that lost must leave the winner's offset alone —
     * reverting it would push every other in-flight request back into the skew that just failed.
     */
    public function correctIfUnchanged(int $expected, int $offsetSec): bool
    {
        if ($this->offsetSec !== $expected) {
            return false;
        }
        $this->offsetSec = $offsetSec;

        return true;
    }

    public function reset(): void
    {
        $this->offsetSec = 0;
    }

    private function base(): int
    {
        return $this->source === null ? time() : ($this->source)();
    }
}
