<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payment/cancel`.
 */
final class PaymentCancelRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Your order reference.
         * Example: "order-1".
         */
        public readonly ?string $order_id = null,
        /** Invoice id in Oblodai. Either uuid or order_id is required; uuid takes priority. */
        public readonly ?string $uuid = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'order_id' => $this->order_id,
            'uuid' => $this->uuid,
        ]);
    }
}
