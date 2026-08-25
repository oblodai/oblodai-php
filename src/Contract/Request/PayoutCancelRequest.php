<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payout/cancel`.
 */
final class PayoutCancelRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /** Id of the payout (or refund) to cancel. */
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
