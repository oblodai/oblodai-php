<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/pay/{id}/select`.
 */
final class PaySelectRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Selected payment currency.
         * Example: "USDT".
         */
        public readonly string $currency,
        /**
         * Selected network.
         * Example: "tron".
         */
        public readonly string|Network $network,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'currency' => $this->currency,
            'network' => $this->network,
        ]);
    }
}
