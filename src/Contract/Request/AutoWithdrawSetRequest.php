<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/auto-withdraw/set`.
 */
final class AutoWithdrawSetRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Destination address (the merchant's external wallet).
         * Example: "TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx".
         */
        public readonly string $address,
        /**
         * Asset to withdraw automatically.
         * Example: "USDT".
         */
        public readonly string $currency,
        /**
         * Network of the destination address.
         * Example: "tron".
         */
        public readonly string|Network $network,
        /**
         * Threshold: the sweep runs once the available balance of the asset reaches this amount; empty uses the network minimum.
         * Example: "100".
         */
        public readonly ?string $min_amount = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'address' => $this->address,
            'currency' => $this->currency,
            'network' => $this->network,
            'min_amount' => $this->min_amount,
        ]);
    }
}
