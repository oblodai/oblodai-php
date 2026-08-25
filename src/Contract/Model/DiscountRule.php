<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/payment/discount/*` entry. */
final class DiscountRule
{
    /** @var list<string> */
    public const KEYS = ['currency', 'network', 'discount_percent'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Currency this discount applies to. */
        public readonly string $currency,
        /** Network this discount applies to. */
        public readonly string $network,
        /** Positive = discount for the payer, negative = markup. */
        public readonly int $discount_percent,
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
            Wire::int($data, 'discount_percent'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
