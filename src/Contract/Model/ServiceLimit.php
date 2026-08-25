<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** Pricing limits on a {@see ServiceMethod} (`/v1/payment/services`, `/v1/payout/services`). */
final class ServiceLimit
{
    /** @var list<string> */
    public const KEYS = ['min_amount', 'max_amount', 'currency'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Minimum accepted amount, in `currency`; null when the asset cannot be priced right now. */
        public readonly ?string $min_amount,
        /** Maximum accepted amount, in `currency`; null when the asset cannot be priced right now. */
        public readonly ?string $max_amount,
        /** Currency the limits are expressed in, when reported. */
        public readonly ?string $currency = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::nullableStr($data, 'min_amount'),
            Wire::nullableStr($data, 'max_amount'),
            Wire::nullableStr($data, 'currency'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
