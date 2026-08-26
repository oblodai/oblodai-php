<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\AmountMode;
use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/payment/link`.
 */
final class PaymentLinkRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Amount mode: fixed | open | range.
         * Example: "open".
         */
        public readonly string|AmountMode $amount_mode,
        /**
         * Price currency — fiat (USD, EUR, RUB, …) or a coin; see pricing_currencies from GET /v1/currencies.
         * Example: "USD".
         */
        public readonly string $currency,
        /**
         * Amount — for fixed mode; required in this mode.
         * Example: "25.00".
         */
        public readonly ?string $amount_fixed = null,
        /** Description on the payment page. */
        public readonly ?string $description = null,
        /** Link lifetime in seconds from creation; 0 (default) — the link never expires. */
        public readonly ?int $expires_in_seconds = null,
        /**
         * Upper bound — for range; required in this mode.
         * Example: "1000.00".
         */
        public readonly ?string $max_amount = null,
        /**
         * Lower bound: an optional floor for open, a required minimum for range.
         * Example: "1.00".
         */
        public readonly ?string $min_amount = null,
        /**
         * Settlement currency (coin) pinned to the link; empty — the buyer chooses the coin.
         * Example: "USDT".
         */
        public readonly ?string $pinned_currency = null,
        /**
         * Settlement network pinned to the link; empty — the buyer chooses the network.
         * Example: "tron".
         */
        public readonly string|Network|null $pinned_network = null,
        /**
         * Title on the payment page.
         * Example: "Support the project".
         */
        public readonly ?string $title = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'amount_mode' => $this->amount_mode,
            'currency' => $this->currency,
            'amount_fixed' => $this->amount_fixed,
            'description' => $this->description,
            'expires_in_seconds' => $this->expires_in_seconds,
            'max_amount' => $this->max_amount,
            'min_amount' => $this->min_amount,
            'pinned_currency' => $this->pinned_currency,
            'pinned_network' => $this->pinned_network,
            'title' => $this->title,
        ]);
    }
}
