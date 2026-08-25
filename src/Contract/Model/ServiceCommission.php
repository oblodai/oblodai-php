<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** Commission schedule on a {@see ServiceMethod} (`/v1/payment/services`, `/v1/payout/services`). */
final class ServiceCommission
{
    /** @var list<string> */
    public const KEYS = ['fee_amount', 'percent', 'currency', 'fee_type'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Fixed fee amount, in `currency`; null when the asset cannot be priced right now. */
        public readonly ?string $fee_amount,
        /** Percent fee, as a decimal string (for example `"1.5"`); null when unpriced. */
        public readonly ?string $percent,
        /** Currency the commission is expressed in. */
        public readonly string $currency,
        /** Pricing mode for this fee (for example `exact`). */
        public readonly string $fee_type,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::nullableStr($data, 'fee_amount'),
            Wire::nullableStr($data, 'percent'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'fee_type'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
