<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/payment/batch`, `/v1/refund/batch`, `/v1/payout/batch`, `/v1/transfer/batch` acknowledgement. */
final class BatchSubmitted
{
    /** @var list<string> */
    public const KEYS = ['batch_id', 'kind', 'status', 'count'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Id to poll via `/v1/batch/info`. */
        public readonly string $batch_id,
        /** Kind of batch (`payment` | `payout` | `refund` | `transfer` | `payout_link`). */
        public readonly string $kind,
        /** Lifecycle: `queued` | `processing` | `done` | `stopped`. */
        public readonly string $status,
        /** Number of items submitted. */
        public readonly int $count,
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
            Wire::int($data, 'count'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
