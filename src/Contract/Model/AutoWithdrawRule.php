<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/auto-withdraw/*` entry. */
final class AutoWithdrawRule
{
    /** @var list<string> */
    public const KEYS = ['currency', 'network', 'address', 'min_amount'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Currency this rule watches. */
        public readonly string $currency,
        /** Network this rule watches. */
        public readonly string $network,
        /** Where the automatic withdrawal is sent. */
        public readonly string $address,
        /** Sweep once the balance reaches this amount. Decimal string. */
        public readonly string $min_amount,
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
            Wire::str($data, 'address'),
            Wire::str($data, 'min_amount'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
