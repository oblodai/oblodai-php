<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/payment`.
 */
final class PaymentRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Amount to pay, in currency.
         * Example: "10".
         */
        public readonly string $amount,
        /**
         * Price currency code: any of the 23 fiats (USD, EUR, RUB, …) or any coin (USDT, BTC, …). JPY and KRW have zero decimal places.
         * Example: "USD".
         */
        public readonly string $currency,
        /** Under/overpayment tolerance, 0–5 %. Overrides the merchant setting. */
        public readonly ?float $accuracy_payment_percent = null,
        /** Private merchant data, echoed back in webhooks (not visible to the buyer). */
        public readonly ?string $additional_data = null,
        /** Allow paying up the remaining amount. */
        public readonly ?bool $is_payment_multiple = null,
        /** Revive an expired invoice by order_id instead of creating a new one. */
        public readonly ?bool $is_refresh = null,
        /**
         * Invoice lifetime in seconds, 300–43200; default 3600. Values outside the range are clamped to the nearest bound.
         * Example: 3600.
         */
        public readonly ?int $lifetime_seconds = null,
        /**
         * Settlement network (e.g. tron, ethereum). Optional — see the currency and network selection modes.
         * Example: "tron".
         */
        public readonly string|Network|null $network = null,
        /**
         * Merchant reference; idempotency key. Strongly recommended.
         * Example: "order-1".
         */
        public readonly ?string $order_id = null,
        /** Payer email. If set, a cheque is sent there automatically after payment; it is also the default recipient for POST /v1/payment/send-email. */
        public readonly ?string $payer_email = null,
        /** Deprecated: % of the network markup charged to the payer (0–100); payer-facing markups are configured via discount. */
        public readonly ?int $subtract = null,
        /**
         * Payment page theme: dark | light.
         * Example: "dark".
         */
        public readonly ?string $theme = null,
        /**
         * Settlement currency — the crypto used for payment. Defaults to currency (only if currency is a coin); with a fiat price set it explicitly or omit it together with network.
         * Example: "USDT".
         */
        public readonly ?string $to_currency = null,
        /** Per-invoice webhook. Requires a registered endpoint (POST /v1/webhooks): delivery is signed with its secret. */
        public readonly ?string $url_callback = null,
        /** "Back to shop" link on the payment page. */
        public readonly ?string $url_return = null,
        /** Redirect after successful payment. */
        public readonly ?string $url_success = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'accuracy_payment_percent' => $this->accuracy_payment_percent,
            'additional_data' => $this->additional_data,
            'is_payment_multiple' => $this->is_payment_multiple,
            'is_refresh' => $this->is_refresh,
            'lifetime_seconds' => $this->lifetime_seconds,
            'network' => $this->network,
            'order_id' => $this->order_id,
            'payer_email' => $this->payer_email,
            'subtract' => $this->subtract,
            'theme' => $this->theme,
            'to_currency' => $this->to_currency,
            'url_callback' => $this->url_callback,
            'url_return' => $this->url_return,
            'url_success' => $this->url_success,
        ]);
    }
}
