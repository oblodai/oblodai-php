<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/link/{id}/checkout`.
 */
final class LinkCheckoutRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Amount entered by the buyer, in the link's price currency; required for open and range, ignored for fixed.
         * Example: "10.00".
         */
        public readonly ?string $amount = null,
        /**
         * Settlement currency — the coin the buyer pays with; needed only if the link did not pin pinned_currency.
         * Example: "USDT".
         */
        public readonly ?string $currency = null,
        /**
         * Settlement network; needed only if the link did not pin pinned_network.
         * Example: "tron".
         */
        public readonly string|Network|null $network = null,
        /** Shop order number from the embedded widget (data-oblodai-order-id); carried over to the invoice and to the webhook for matching with the order; not an idempotency key. */
        public readonly ?string $order_id = null,
        /**
         * Buyer email — the cheque is sent there automatically after payment.
         * Example: "buyer@example.com".
         */
        public readonly ?string $payer_email = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'network' => $this->network,
            'order_id' => $this->order_id,
            'payer_email' => $this->payer_email,
        ]);
    }
}
