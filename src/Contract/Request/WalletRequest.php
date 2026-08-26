<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/wallet`.
 */
final class WalletRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Symbol of the receiving currency (USDT, BTC, ETH, …).
         * Example: "USDT".
         */
        public readonly string $currency,
        /**
         * Receiving network (tron, ethereum, bitcoin, …).
         * Example: "tron".
         */
        public readonly string|Network $network,
        /**
         * Your customer/order identifier. Pins a dedicated permanent address to the customer.
         * Example: "client-42".
         */
        public readonly ?string $order_id = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'currency' => $this->currency,
            'network' => $this->network,
            'order_id' => $this->order_id,
        ]);
    }
}
