<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payment/send-email`.
 */
final class PaymentSendEmailRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Where to send it. Defaults to the payer_email set on the payment.
         * Example: "buyer@example.com".
         */
        public readonly ?string $email = null,
        /**
         * Your order reference.
         * Example: "order-1".
         */
        public readonly ?string $order_id = null,
        /** Payment id in Oblodai. Either uuid or order_id is required. */
        public readonly ?string $uuid = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'email' => $this->email,
            'order_id' => $this->order_id,
            'uuid' => $this->uuid,
        ]);
    }
}
