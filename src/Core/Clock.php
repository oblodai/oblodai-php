<?php

declare(strict_types=1);

namespace Oblodai\Core;

/**
 * Injectable clock for signing. The core rejects timestamps more than ±300 s from its own time;
 * a host with a drifting clock would get `merchant.bad_signature` on every call. The transport
 * learns the server's time from the `Date` header of a signature-failure response, re-signs once,
 * and keeps the offset only if that re-signed attempt got past authentication.
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
        $serverTs = strtotime($dateHeader);
        if ($serverTs === false) {
            return null;
        }
        $offset = $serverTs - $this->base();

        return abs($offset) > self::MAX_PLAUSIBLE_OFFSET_SECONDS ? null : $offset;
    }

    public function correct(int $offsetSec): void
    {
        $this->offsetSec = $offsetSec;
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
