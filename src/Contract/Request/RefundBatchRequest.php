<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\BatchOnError;

/**
 * Body of `POST /v1/refund/batch`.
 */
final class RefundBatchRequest implements RequestBody
{
    use NormalizesFields;

    /**
     * @param list<array<string, mixed>>|list<\Oblodai\Contract\Request\RequestBody> $refunds
     */
    public function __construct(
        /**
         * Array of 1 to 5000 items — the same fields as POST /v1/payment/refund; every item requires reference (idempotency key) and either uuid or order_id of the payment.
         * Each item:
         *   - `address` — Refund destination address. Defaults to the payment's payer_address; required only for Bitcoin/UTXO.
         *   - `amount` — Partial amount. Defaults to the full amount received.
         *   - `network` — Network.
         *   - `order_id` — Your order reference for the payment. Either uuid or order_id is required.
         *   - `reference` — Optional refund idempotency key: distinguishes two different refunds with the same (payment, address, amount); a repeat with the same value is deduplicated. This is not order_id.
         *   - `uuid` — Payment id. Either uuid or order_id is required.
         */
        public readonly array $refunds,
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
            'refunds' => $this->refunds,
            'on_error' => $this->on_error,
        ]);
    }
}
