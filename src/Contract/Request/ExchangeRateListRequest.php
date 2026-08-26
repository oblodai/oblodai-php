<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/exchange-rate/list`.
 */
final class ExchangeRateListRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Currency code. If set, only its rate is returned. If empty or the body is {}, rates for all currencies are returned.
         * Example: "ETH".
         */
        public readonly ?string $currency_from = null,
        /** Quote currency: USDT by default; any pricing asset, including fiats with a direct feed (EUR, RUB, …). */
        public readonly ?string $currency_to = null,
        /** Page size, 1–100; default 25. */
        public readonly ?int $limit = null,
        /** Offset from the start of the list; default 0. */
        public readonly ?int $offset = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'currency_from' => $this->currency_from,
            'currency_to' => $this->currency_to,
            'limit' => $this->limit,
            'offset' => $this->offset,
        ]);
    }
}
