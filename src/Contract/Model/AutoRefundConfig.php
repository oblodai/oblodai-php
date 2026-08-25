<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/payment/autorefund/*`. */
final class AutoRefundConfig
{
    /** @var list<string> */
    public const KEYS = ['overpay', 'underpay', 'configured'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** True — an overpayment is automatically refunded. */
        public readonly bool $overpay,
        /** True — an underpayment is automatically refunded (instead of waiting for the rest). */
        public readonly bool $underpay,
        /** `get` only: whether the merchant ever set it. */
        public readonly ?bool $configured = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::bool($data, 'overpay'),
            Wire::bool($data, 'underpay'),
            Wire::nullableBool($data, 'configured'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
