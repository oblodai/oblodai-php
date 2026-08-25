<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payout/link/list`.
 */
final class PayoutLinkListRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * How many links to return per page.
         * Example: 50.
         */
        public readonly ?int $limit = null,
        /**
         * Offset from the start of the list (paging).
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
