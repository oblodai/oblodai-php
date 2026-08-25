<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/payment/accepted/*` entry. */
final class AcceptedMethod
{
    /** @var list<string> */
    public const KEYS = ['currency', 'network', 'available'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Currency of this payment method. */
        public readonly string $currency,
        /** Network of this payment method. */
        public readonly string $network,
        /** True — the method is currently offered to payers. */
        public readonly bool $available,
        /** Why it is unavailable, when it is. */
        public readonly ?string $reason = null,
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
            Wire::bool($data, 'available'),
            Wire::nullableStr($data, 'reason'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
