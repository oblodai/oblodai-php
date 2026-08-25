<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** One currency, nested in `Currencies.currencies` (`GET /v1/currencies`). */
final class CurrencyInfo
{
    /** @var list<string> */
    public const KEYS = ['currency', 'decimals', 'networks'];

    /**
     * @param list<CurrencyNetwork> $networks
     * @param array<string, mixed>  $raw
     */
    public function __construct(
        /** The currency's code. */
        public readonly string $currency,
        /** Number of decimal places this currency is quoted with. */
        public readonly int $decimals,
        /** Networks this currency settles on. */
        public readonly array $networks,
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
            array_map(
                static fn (array $network): CurrencyNetwork => CurrencyNetwork::fromArray($network),
                Wire::rows($data, 'networks')
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
