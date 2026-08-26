<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payment/accuracy/set`.
 */
final class PaymentAccuracySetRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Enable/disable the tolerance.
         * Example: true.
         */
        public readonly bool $enabled,
        /**
         * Tolerance in percent, 1–5. Required when enabled: true; ignored when enabled: false (reset to 0). Capped at 5 %.
         * Example: 2.
         */
        public readonly ?int $accuracy_percent = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'enabled' => $this->enabled,
            'accuracy_percent' => $this->accuracy_percent,
        ]);
    }
}
