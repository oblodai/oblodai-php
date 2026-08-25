<?php

declare(strict_types=1);

namespace Oblodai\Exception;

use JsonSerializable;
use RuntimeException;
use Throwable;

/**
 * Error model. One family, `OblodaiException`, mirrors the core's error envelope:
 *
 *   { "error": { "code", "message", "field"?, "retryable", "retry_after"?, "request_id"? } }
 *
 * `retryable` is authoritative when the core wrote the envelope: it is the core's own classification
 * of the failure. A response without an envelope (a proxy 502, an HTML 503) is `synthetic` — the
 * core never saw or never answered the request — and is retried only when repeating is safe.
 * Subclasses exist for `instanceof` ergonomics; the discriminator is always `errorCode`.
 */
class OblodaiException extends RuntimeException implements JsonSerializable
{
    /** The decoded error body (or raw text when the body was not JSON). Never serialized. */
    private mixed $raw;

    public function __construct(
        /** Stable machine code (`family.reason`), e.g. `payout.insufficient_funds`. */
        public readonly string $errorCode,
        string $message,
        /** HTTP status, or 0 when no response was received. */
        public readonly int $httpStatus = 0,
        /** Whether repeating the identical request can succeed later. */
        public readonly bool $retryable = false,
        /** Seconds to wait before retrying, when the core (or a `Retry-After` header) said so. */
        public readonly ?int $retryAfter = null,
        /** Server-side request id — quote it when contacting support. */
        public readonly ?string $requestId = null,
        /** The request field the error refers to, for validation failures. */
        public readonly ?string $field = null,
        /** No core envelope: the answer came from something in front of the core. */
        public readonly bool $synthetic = false,
        mixed $raw = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->raw = $raw;
    }

    /** Code family (`payout` in `payout.insufficient_funds`). */
    public function family(): string
    {
        $dot = strpos($this->errorCode, '.');

        return $dot === false ? $this->errorCode : substr($this->errorCode, 0, $dot);
    }

    /** The undecoded error body. Kept off `jsonSerialize()` so a logger never dumps it. */
    public function raw(): mixed
    {
        return $this->raw;
    }

    /**
     * Structured-logger friendly: keeps the message, drops the raw body.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => static::class,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'httpStatus' => $this->httpStatus,
            'retryable' => $this->retryable,
            'retryAfter' => $this->retryAfter,
            'requestId' => $this->requestId,
            'field' => $this->field,
        ];
    }
}
