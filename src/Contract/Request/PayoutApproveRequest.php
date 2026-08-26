<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payout/approve`.
 */
final class PayoutApproveRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /** Payout id. */
        public readonly string $uuid,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'uuid' => $this->uuid,
        ]);
    }
}
