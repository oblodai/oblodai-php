<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/split/config/set`.
 */
final class SplitConfigSetRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * How many seconds to defer split settlement; range 0–7776000 (up to 90 days). 0 — send the shares immediately: you take on the risk that a refund becomes impossible.
         * Example: 172800.
         */
        public readonly int $refund_hold_seconds,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'refund_hold_seconds' => $this->refund_hold_seconds,
        ]);
    }
}
