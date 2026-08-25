<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** One currency line of the merchant balance (`balance.merchant` on `/v1/balance`). */
final class BalanceEntry
{
    /** @var list<string> */
    public const KEYS = ['currency', 'balance'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Currency this balance is denominated in. */
        public readonly string $currency,
        /** Available (spendable) balance. Decimal string. */
        public readonly string $balance,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'currency'),
            Wire::str($data, 'balance'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
