<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `GET /v1/currencies`. */
final class Currencies
{
    /** @var list<string> */
    public const KEYS = ['currencies', 'pricing_currencies'];

    /**
     * @param list<CurrencyInfo>      $currencies
     * @param list<PricingCurrency>   $pricing_currencies
     * @param array<string, mixed>    $raw
     */
    public function __construct(
        /** Coins the gateway accepts, with their networks. */
        public readonly array $currencies,
        /** Currencies an invoice can be priced in (fiat and coins). */
        public readonly array $pricing_currencies,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(
                static fn (array $currency): CurrencyInfo => CurrencyInfo::fromArray($currency),
                Wire::rows($data, 'currencies')
            ),
            array_map(
                static fn (array $currency): PricingCurrency => PricingCurrency::fromArray($currency),
                Wire::rows($data, 'pricing_currencies')
            ),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
