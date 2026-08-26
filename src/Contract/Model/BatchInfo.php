<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\BatchOnError;

/** `POST /v1/batch/info`. */
final class BatchInfo
{
    /** @var list<string> */
    public const KEYS = [
        'batch_id', 'kind', 'status', 'on_error', 'total', 'succeeded', 'failed', 'items',
        'created_at', 'updated_at',
    ];

    /**
     * @param OpenEnum<BatchOnError> $on_error
     * @param list<BatchInfoItem>  $items
     * @param array<string, mixed> $raw
     */
    public function __construct(
        /** Batch id. */
        public readonly string $batch_id,
        /** Batch kind: `payment` | `refund` | `payout`. */
        public readonly string $kind,
        /**
         * Batch status: `pending` | `processing` | `completed` | `stopped`. TERMINAL states are
         * `completed` AND `stopped` (poll until one of them, not only until `completed`):
         * `completed` = processing reached the end, `stopped` = a batch with `on_error: "stop"`
         * halted on the first error (the remaining items were skipped and counted in `failed`).
         * Neither means "everything succeeded" — check `succeeded`/`failed`.
         */
        public readonly string $status,
        /** Error handling mode the batch was submitted with: `continue` | `stop`. */
        public readonly OpenEnum $on_error,
        /** Total items in the batch; counted over the whole batch and independent of pagination. */
        public readonly int $total,
        /** Processed successfully. */
        public readonly int $succeeded,
        /** Failed with an error (with `on_error: "stop"`, skipped items are counted here too). */
        public readonly int $failed,
        /** Page of items with the result or the error for each one. */
        public readonly array $items,
        /** Batch creation time (RFC 3339, UTC). */
        public readonly string $created_at,
        /** Time of the last change (RFC 3339, UTC). */
        public readonly string $updated_at,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'batch_id'),
            Wire::str($data, 'kind'),
            Wire::str($data, 'status'),
            Wire::enum(BatchOnError::class, $data, 'on_error'),
            Wire::int($data, 'total'),
            Wire::int($data, 'succeeded'),
            Wire::int($data, 'failed'),
            array_map(
                static fn (array $item): BatchInfoItem => BatchInfoItem::fromArray($item),
                Wire::rows($data, 'items')
            ),
            Wire::str($data, 'created_at'),
            Wire::str($data, 'updated_at'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
