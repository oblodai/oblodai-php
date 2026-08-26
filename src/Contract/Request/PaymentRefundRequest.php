<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/payment/refund`.
 */
final class PaymentRefundRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /** Refund destination address. Defaults to the payment's payer_address; required only for Bitcoin/UTXO. */
        public readonly ?string $address = null,
        /**
         * Partial amount. Defaults to the full amount received.
         * Example: "10".
         */
        public readonly ?string $amount = null,
        /**
         * Network.
         * Example: "tron".
         */
        public readonly string|Network|null $network = null,
        /**
         * Your order reference for the payment. Either uuid or order_id is required.
         * Example: "order-1".
         */
        public readonly ?string $order_id = null,
        /** Optional refund idempotency key: distinguishes two different refunds with the same (payment, address, amount); a repeat with the same value is deduplicated. This is not order_id. */
        public readonly ?string $reference = null,
        /** Payment id. Either uuid or order_id is required. */
        public readonly ?string $uuid = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'address' => $this->address,
            'amount' => $this->amount,
            'network' => $this->network,
            'order_id' => $this->order_id,
            'reference' => $this->reference,
            'uuid' => $this->uuid,
        ]);
    }
}
