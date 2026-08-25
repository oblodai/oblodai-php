<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/auto-withdraw/delete`.
 */
final class AutoWithdrawDeleteRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Asset whose auto-withdrawal to switch off.
         * Example: "USDT".
         */
        public readonly string $currency,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'currency' => $this->currency,
        ]);
    }
}
