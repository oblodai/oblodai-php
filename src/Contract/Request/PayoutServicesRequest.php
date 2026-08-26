<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payout/services`.
 */
final class PayoutServicesRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
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
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'limit' => $this->limit,
            'offset' => $this->offset,
        ]);
    }
}
