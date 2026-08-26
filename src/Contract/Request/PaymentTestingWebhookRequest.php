<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\PaymentStatus;

/**
 * Body of `POST /v1/payment/testing-webhook`.
 */
final class PaymentTestingWebhookRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Status in the body. Default paid.
         * Example: "paid".
         */
        public readonly string|PaymentStatus|null $status = null,
        /**
         * Where to send the test body. If not provided, delivery goes to the project's registered endpoint; without an endpoint it fails with webhook.no_endpoint. Signed with the project endpoint's secret, including when url is passed explicitly.
         * Example: "https://shop.example/hook".
         */
        public readonly ?string $url = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'status' => $this->status,
            'url' => $this->url,
        ]);
    }
}
