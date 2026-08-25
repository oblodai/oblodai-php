<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** The rolling-week counters nested in `ReferralInfo.week` (`/v1/referral/info`). */
final class ReferralWeek
{
    /** @var list<string> */
    public const KEYS = ['referred_count', 'earnings_by_asset'];

    /**
     * @param array<string, string> $earnings_by_asset
     * @param array<string, mixed>  $raw
     */
    public function __construct(
        /** Referrals gained in the current week. */
        public readonly int $referred_count,
        /** Referral earnings in the current week, by asset. Decimal strings. */
        public readonly array $earnings_by_asset,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::int($data, 'referred_count'),
            Wire::stringMap($data, 'earnings_by_asset'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
