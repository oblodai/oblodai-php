<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/payout/validate`.
 */
final class PayoutValidateRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /** Recipient address. */
        public readonly string $address,
        /**
         * Payout amount, in currency.
         * Example: "25".
         */
        public readonly string $amount,
        /**
         * Currency code (for example USDT).
         * Example: "USDT".
         */
        public readonly string $currency,
        /**
         * Your payout number; idempotency key.
         * Example: "payout-1".
         */
        public readonly string $order_id,
        /**
         * Fund the payout by converting the balance. USDT → currency only.
         * Example: "USDT".
         */
        public readonly ?string $from_currency = null,
        /** Who pays the network fee: true — amount+fee is debited from the balance and the recipient receives amount; false — the recipient receives amount-fee; not provided — the project fee-config. */
        public readonly ?bool $is_subtract = null,
        /** Destination tag/memo (TON Jetton). Maximum 120 characters. */
        public readonly ?string $memo = null,
        /**
         * Network (tron, ethereum, …). Required for coins with several networks.
         * Example: "tron".
         */
        public readonly string|Network|null $network = null,
        /** Origin label: api (default) or manual. */
        public readonly ?string $source = null,
        /** Custom webhook URL for this payout (passes the SSRF check). Requires a registered endpoint (POST /v1/webhooks): delivery is signed with its secret. */
        public readonly ?string $url_callback = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'address' => $this->address,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'order_id' => $this->order_id,
            'from_currency' => $this->from_currency,
            'is_subtract' => $this->is_subtract,
            'memo' => $this->memo,
            'network' => $this->network,
            'source' => $this->source,
            'url_callback' => $this->url_callback,
        ]);
    }
}
