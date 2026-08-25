<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;
use Oblodai\Contract\Enum\PayoutStatus;

/**
 * Body of `POST /v1/test-webhook/payout`.
 */
final class TestWebhookPayoutRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Where to send the test body.
         * Example: "https://shop.example/oblodai/callback".
         */
        public readonly string $url_callback,
        /**
         * Currency in the body.
         * Example: "USDT".
         */
        public readonly ?string $currency = null,
        /**
         * Network in the body.
         * Example: "tron".
         */
        public readonly string|Network|null $network = null,
        /** Your order_id, which is put into the test event body. */
        public readonly ?string $order_id = null,
        /**
         * Status in the body — from the status dictionary of this event type. Default paid (confirmed for a payout).
         * Example: "paid".
         */
        public readonly string|PayoutStatus|null $status = null,
        /** UUID of the object (payment, wallet or payout) put into the test event body. */
        public readonly ?string $uuid = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'url_callback' => $this->url_callback,
            'currency' => $this->currency,
            'network' => $this->network,
            'order_id' => $this->order_id,
            'status' => $this->status,
            'uuid' => $this->uuid,
        ]);
    }
}
