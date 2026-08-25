<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** Element of every batch listing (`/v1/payout/mass`, `/v1/payout/link/batch`, `/v1/batch/info`). */
final class BatchElement
{
    /** @var list<string> */
    public const KEYS = ['idx', 'ok', 'order_id', 'result', 'message', 'error_code', 'http_status'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Index of the item in the original array (zero-based). */
        public readonly int $idx,
        /** Item outcome: true on success. */
        public readonly bool $ok,
        /** The item's order_id, if you set one. */
        public readonly ?string $order_id = null,
        /** Decoded result of the successful operation, or the raw object when no decoder is given. */
        public readonly mixed $result = null,
        /** Human-readable error message; only when `ok` is false. */
        public readonly ?string $message = null,
        /** Machine-readable error code; only when `ok` is false. */
        public readonly ?string $error_code = null,
        /** HTTP status a single call would have returned. */
        public readonly ?int $http_status = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed>                        $data
     * @param (callable(array<string, mixed>): mixed)|null $decode applied to the raw `result` object, when present
     */
    public static function fromArray(array $data, ?callable $decode = null): self
    {
        $result = null;
        if (array_key_exists('result', $data) && is_array($data['result'])) {
            /** @var array<string, mixed> $raw */
            $raw = $data['result'];
            $result = $decode !== null ? $decode($raw) : $raw;
        }

        return new self(
            Wire::int($data, 'idx'),
            Wire::bool($data, 'ok'),
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
