<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** One currency invoices can be priced in, nested in `Currencies.pricing_currencies` (`GET /v1/currencies`). */
final class PricingCurrency
{
    /** @var list<string> */
    public const KEYS = ['currency', 'decimals', 'fiat'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** The currency's code. */
        public readonly string $currency,
        /** Number of decimal places this currency is quoted with. */
        public readonly int $decimals,
        /** True — a fiat currency, false — a coin. */
        public readonly bool $fiat,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'currency'),
            Wire::int($data, 'decimals'),
            Wire::bool($data, 'fiat'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
