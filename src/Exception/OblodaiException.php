<?php

declare(strict_types=1);

namespace Oblodai\Exception;

use JsonSerializable;
use RuntimeException;
use Throwable;

/**
 * Error model. One family, `OblodaiException`, mirrors the core's error envelope:
 *
 *   { "error": { "code", "message", "field"?, "details"?, "retryable", "retry_after"?, "request_id"? } }
 *
 * `retryable` is authoritative when the core wrote the envelope: it is the core's own classification
 * of the failure. A response without an envelope (a proxy 502, an HTML 503) is `synthetic` — the
 * core never saw or never answered the request — and is retried only when repeating is safe.
 * Subclasses exist for `instanceof` ergonomics; the discriminator is always `errorCode`.
 *
 * `getMessage()` (and `(string) $e`) reads in a log line: `[code] text (request_id=…)`; the
 * gateway's own words alone are `$e->detail`.
 */
class OblodaiException extends RuntimeException implements JsonSerializable
{
    /**
     * Plausibility ceiling for a decoded `retry_after` / `Retry-After`, seconds (24 h). It exists so
     * a hostile or broken value can neither overflow the conversion to int nor park a caller's own
     * scheduler forever; the SDK's own backoff is capped much lower, by `Retry::$maxRetryAfterMs`.
     */
    public const MAX_RETRY_AFTER_SECONDS = 86_400;

    /** The decoded error body (or raw text when the body was not JSON). Never serialized. */
    private mixed $raw;

    /** The error text alone, without the code and the request id. */
    public readonly string $detail;

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
        /**
         * Machine-readable facts about the refusal, keys documented by its code (e.g.
         * `cli.permission_denied` carries `required_role` and `role`); null when absent.
         *
         * @var array<string, string>|null
         */
        public readonly ?array $details = null,
    ) {
        parent::__construct(self::render($errorCode, $message, $requestId), 0, $previous);
        $this->detail = $message;
        $this->raw = $raw;
    }

    /** `[code] text (request_id=…)`; without a request id, just `[code] text`. */
    public static function render(string $errorCode, string $message, ?string $requestId): string
    {
        $out = '[' . $errorCode . '] ' . $message;

        return $requestId !== null && $requestId !== '' ? $out . ' (request_id=' . $requestId . ')' : $out;
    }

    /** The rendered line, like `getMessage()` — not PHP's default dump with the stack trace. */
    public function __toString(): string
    {
        return $this->getMessage();
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
            'message' => $this->detail,
            'httpStatus' => $this->httpStatus,
            'retryable' => $this->retryable,
            'retryAfter' => $this->retryAfter,
            'requestId' => $this->requestId,
            'field' => $this->field,
            'details' => $this->details,
        ];
    }
}
