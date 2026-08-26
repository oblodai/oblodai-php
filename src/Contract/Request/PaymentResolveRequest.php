<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/payment/resolve`.
 */
final class PaymentResolveRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * accept — accept the partial payment, refund — return it to the payer.
         * Example: "accept".
         */
        public readonly string $action,
        /** refund only: refund address. Defaults to the payment's recorded payer_address; if it is empty (Bitcoin/UTXO) the address is required, otherwise refund.no_address. */
        public readonly ?string $address = null,
        /** refund only: refund network, defaults to the payment network. */
        public readonly string|Network|null $network = null,
        /**
         * Your payment identifier.
         * Example: "ord-1001".
         */
        public readonly ?string $order_id = null,
        /** refund only: your refund deduplication key. */
        public readonly ?string $reference = null,
        /** Payment UUID. Either uuid or order_id is required. */
        public readonly ?string $uuid = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'action' => $this->action,
            'address' => $this->address,
            'network' => $this->network,
            'order_id' => $this->order_id,
            'reference' => $this->reference,
            'uuid' => $this->uuid,
        ]);
    }
}
