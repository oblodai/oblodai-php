<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payment/link/toggle`.
 */
final class PaymentLinkToggleRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * true — the link accepts payments; false — disabled (the page shows the link as inactive).
         * Example: false.
         */
        public readonly bool $active,
        /**
         * Payment link identifier.
         * Example: "5d3f2a71-9c84-4b0e-8d17-3e6a2c9f1b40".
         */
        public readonly string $link_id,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'active' => $this->active,
            'link_id' => $this->link_id,
        ]);
    }
}
