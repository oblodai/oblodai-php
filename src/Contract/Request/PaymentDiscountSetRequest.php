<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/payment/discount/set`.
 */
final class PaymentDiscountSetRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Percentage, from -99 to 99. Positive is a discount, negative is a markup.
         * Example: 3.
         */
        public readonly int $discount_percent,
        /**
         * Currency. Empty = global default for all coins.
         * Example: "USDT".
         */
        public readonly ?string $currency = null,
        /**
         * Network. Empty = any network of this currency.
         * Example: "tron".
         */
        public readonly string|Network|null $network = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'discount_percent' => $this->discount_percent,
            'currency' => $this->currency,
            'network' => $this->network,
        ]);
    }
}
