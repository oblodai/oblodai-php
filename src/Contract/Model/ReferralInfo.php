<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/referral/info`. */
final class ReferralInfo
{
    /** @var list<string> */
    public const KEYS = ['code', 'link', 'tier_bps', 'referred_count', 'earnings_by_asset', 'week'];

    /**
     * @param list<int>              $tier_bps
     * @param array<string, string>  $earnings_by_asset
     * @param array<string, mixed>   $raw
     */
    public function __construct(
        /** Your referral code. */
        public readonly string $code,
        /** Your referral link, ready to share. */
        public readonly string $link,
        /** Referral tiers, basis points. */
        public readonly array $tier_bps,
        /** Total referrals to date. */
        public readonly int $referred_count,
        /** Total referral earnings, by asset. Decimal strings. */
        public readonly array $earnings_by_asset,
        /** Same counters, scoped to the current rolling week. */
        public readonly ReferralWeek $week,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'code'),
            Wire::str($data, 'link'),
            Wire::ints($data, 'tier_bps'),
            Wire::int($data, 'referred_count'),
            Wire::stringMap($data, 'earnings_by_asset'),
            ReferralWeek::fromArray(Wire::obj($data, 'week')),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
