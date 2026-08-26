<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payment/accepted/set`.
 */
final class PaymentAcceptedSetRequest implements RequestBody
{
    use NormalizesFields;

    /**
     * @param list<array<string, mixed>>|list<\Oblodai\Contract\Request\RequestBody> $accepted
     */
    public function __construct(
        /**
         * The full list of currency+network pairs payers may use; an empty list accepts everything in the catalog.
         * Each item:
         *   - `currency` (required) — Asset code.
         *   - `network` (required) — Asset network.
         */
        public readonly array $accepted,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'accepted' => $this->accepted,
        ]);
    }
}
