<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payment/link/info`.
 */
final class PaymentLinkInfoRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Payment link identifier.
         * Example: "5d3f2a71-9c84-4b0e-8d17-3e6a2c9f1b40".
         */
        public readonly string $link_id,
        /**
         * Page size for the link's payments, 1–100; out of range falls back to 25.
         * Example: 25.
         */
        public readonly ?int $limit = null,
        /**
         * Offset within the link's payments.
         * Example: 0.
         */
        public readonly ?int $offset = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'link_id' => $this->link_id,
            'limit' => $this->limit,
            'offset' => $this->offset,
        ]);
    }
}
