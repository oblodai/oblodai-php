<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\PaymentStatus;

/**
 * Body of `POST /v1/payment/history`.
 */
final class PaymentHistoryRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Ignored on this route (payout history only).
         * Example: "payout".
         */
        public readonly ?string $kind = null,
        /**
         * Page size, 1–100; out of range falls back to 25.
         * Example: 25.
         */
        public readonly ?int $limit = null,
        /**
         * Offset from the start of the list (newest first).
         * Example: 0.
         */
        public readonly ?int $offset = null,
        /**
         * Filter by status (an exact value from the status vocabulary); empty returns all.
         * Example: "paid".
         */
        public readonly string|PaymentStatus|null $status = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'kind' => $this->kind,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'status' => $this->status,
        ]);
    }
}
