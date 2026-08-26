<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/batch/info`.
 */
final class BatchInfoRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Batch id from the submit response.
         * Example: "9f4c1a2b-77de-4a55-9c1f-0e2b3d4a5f60".
         */
        public readonly string $batch_id,
        /**
         * How many items to return in items (pagination).
         * Example: 100.
         */
        public readonly ?int $limit = null,
        /**
         * Offset over items.
         * Example: 0.
         */
        public readonly ?int $offset = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'batch_id' => $this->batch_id,
            'limit' => $this->limit,
            'offset' => $this->offset,
        ]);
    }
}
