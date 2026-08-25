<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payout/link/cancel`.
 */
final class PayoutLinkCancelRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /** Payout link id (link_id from the creation response). */
        public readonly string $link_id,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'link_id' => $this->link_id,
        ]);
    }
}
