<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\BatchOnError;

/**
 * Body of `POST /v1/payment/batch`.
 */
final class PaymentBatchRequest implements RequestBody
{
    use NormalizesFields;

    /**
     * @param list<array<string, mixed>>|list<\Oblodai\Contract\Request\RequestBody> $payments
     */
    public function __construct(
        /**
         * Array of 1 to 5000 items — the same fields as POST /v1/payment; set order_id on every item: results are matched by it and it protects against duplicates.
         * Each item:
         *   - `accuracy_payment_percent` — Under/overpayment tolerance, 0–5 %. Overrides the merchant setting.
         *   - `additional_data` — Private merchant data, echoed back in webhooks (not visible to the buyer).
         *   - `amount` (required) — Amount to pay, in currency.
         *   - `currency` (required) — Price currency code: any of the 23 fiats (USD, EUR, RUB, …) or any coin (USDT, BTC, …). JPY and KRW have zero decimal places.
         *   - `is_payment_multiple` — Allow paying up the remaining amount.
         *   - `is_refresh` — Revive an expired invoice by order_id instead of creating a new one.
         *   - `lifetime_seconds` — Invoice lifetime in seconds, 300–43200; default 3600. Values outside the range are clamped to the nearest bound.
         *   - `network` — Settlement network (e.g. tron, ethereum). Optional — see the currency and network selection modes.
         *   - `order_id` — Merchant reference; idempotency key. Strongly recommended.
         *   - `payer_email` — Payer email. If set, a cheque is sent there automatically after payment; it is also the default recipient for POST /v1/payment/send-email.
         *   - `subtract` — Deprecated: % of the network markup charged to the payer (0–100); payer-facing markups are configured via discount.
         *   - `theme` — Payment page theme: dark | light.
         *   - `to_currency` — Settlement currency — the crypto used for payment. Defaults to currency (only if currency is a coin); with a fiat price set it explicitly or omit it together with network.
         *   - `url_callback` — Per-invoice webhook. Requires a registered endpoint (POST /v1/webhooks): delivery is signed with its secret.
         *   - `url_return` — "Back to shop" link on the payment page.
         *   - `url_success` — Redirect after successful payment.
         */
        public readonly array $payments,
        /**
         * What to do when an item fails: continue (default) — process the rest; stop — halt processing after the first error.
         * Example: "continue".
         */
        public readonly string|BatchOnError|null $on_error = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'payments' => $this->payments,
            'on_error' => $this->on_error,
        ]);
    }
}
