<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** Item of `POST /v1/batch/info` (`items`). */
final class BatchInfoItem
{
    /** @var list<string> */
    public const KEYS = [
        'idx', 'ok', 'order_id', 'status', 'result', 'message', 'error_code', 'http_status',
    ];

    /**
     * @param array<string, mixed>|null $result
     * @param array<string, mixed>      $raw
     */
    public function __construct(
        /** Index of the item in the original array (zero-based). */
        public readonly int $idx,
        /** Item status: `pending` | `processing` | `done` | `error`. */
        public readonly string $status,
        /** Item outcome: true when status is "done", false when status is "error"; absent while pending. */
        public readonly ?bool $ok = null,
        /** The item's order_id, if you set one; not always present. */
        public readonly ?string $order_id = null,
        /** Result of the successful operation — the same object a single call would return; only when status is "done". */
        public readonly ?array $result = null,
        /** Human-readable error message; only when status is "error". */
        public readonly ?string $message = null,
        /** Machine-readable error code; only when status is "error". */
        public readonly ?string $error_code = null,
        /** HTTP status a single call would have returned; absent if the item never reached the handler. */
        public readonly ?int $http_status = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $result = array_key_exists('result', $data) && is_array($data['result'])
            ? Wire::obj($data, 'result')
            : null;

        return new self(
            Wire::int($data, 'idx'),
            Wire::str($data, 'status'),
            Wire::nullableBool($data, 'ok'),
            Wire::nullableStr($data, 'order_id'),
            $result,
            Wire::nullableStr($data, 'message'),
            Wire::nullableStr($data, 'error_code'),
            Wire::nullableInt($data, 'http_status'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
