<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** Item of `POST /v1/payment/services` and `POST /v1/payout/services`. */
final class ServiceMethod
{
    /** @var list<string> */
    public const KEYS = ['currency', 'network', 'is_available', 'limit', 'commission'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Asset this pricing applies to. */
        public readonly string $currency,
        /** Network this pricing applies to. */
        public readonly string $network,
        /** Whether the method currently accepts payments/payouts. */
        public readonly bool $is_available,
        /** Limits are null when the asset cannot be priced right now. */
        public readonly ServiceLimit $limit,
        /** Fee schedule for this method. */
        public readonly ServiceCommission $commission,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'currency'),
            Wire::str($data, 'network'),
            Wire::bool($data, 'is_available'),
            ServiceLimit::fromArray(Wire::obj($data, 'limit')),
            ServiceCommission::fromArray(Wire::obj($data, 'commission')),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
