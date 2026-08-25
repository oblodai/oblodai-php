<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `POST /v1/test-webhook/*` and `POST /v1/payment/testing-webhook`. */
final class WebhookTestResult
{
    /** @var list<string> */
    public const KEYS = ['ok', 'signed', 'status_code'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Whether the test call was accepted. */
        public readonly bool $ok,
        /** Whether the payload was signed with your secret. */
        public readonly bool $signed,
        /** HTTP status the receiver returned; absent when it could not be reached (see `error`). */
        public readonly ?int $status_code = null,
        /** Why the receiver could not be reached, when `status_code` is absent. */
        public readonly ?string $error = null,
        /** The URL tested; `POST /v1/payment/testing-webhook` only. */
        public readonly ?string $url = null,
        /** Round-trip time in milliseconds; `POST /v1/payment/testing-webhook` only. */
        public readonly ?int $duration_ms = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::bool($data, 'ok'),
            Wire::bool($data, 'signed'),
            Wire::nullableInt($data, 'status_code'),
            Wire::nullableStr($data, 'error'),
            Wire::nullableStr($data, 'url'),
            Wire::nullableInt($data, 'duration_ms'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
