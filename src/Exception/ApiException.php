<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/** The core (or something in front of it) answered with an error status. */
class ApiException extends OblodaiException
{
    /** Statuses a response without an envelope may carry transiently (LB/proxy/timeouts). */
    private const TRANSIENT_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    /**
     * Build the right subclass from an error envelope (or a synthesized one) and the HTTP status.
     *
     * @param array{code?: string, message?: string, field?: string, retryable?: bool, retry_after?: int|float|string, request_id?: string} $detail
     */
    public static function from(
        int $httpStatus,
        array $detail,
        mixed $raw = null,
        bool $synthetic = false,
        ?int $retryAfterHeader = null,
    ): self {
        $code = ($detail['code'] ?? '') !== '' ? (string) $detail['code'] : 'internal';
        $message = ($detail['message'] ?? '') !== ''
            ? (string) $detail['message']
            : sprintf('request failed with HTTP %d (%s)', $httpStatus, $detail['code'] ?? 'no envelope');
        $retryable = $synthetic
            ? in_array($httpStatus, self::TRANSIENT_STATUSES, true)
            : (bool) ($detail['retryable'] ?? ($httpStatus === 429 || $httpStatus === 503));
        $retryAfter = isset($detail['retry_after']) ? (int) $detail['retry_after'] : $retryAfterHeader;

        $class = match (true) {
            $code === 'idempotency.key_reused' => IdempotencyConflictException::class,
            $httpStatus === 400 => ValidationException::class,
            $httpStatus === 401 => AuthenticationException::class,
            $httpStatus === 403 => PermissionException::class,
            $httpStatus === 404 => NotFoundException::class,
            $httpStatus === 409 => ConflictException::class,
            $httpStatus === 429 => RateLimitException::class,
            $httpStatus === 503 => UnavailableException::class,
            $httpStatus >= 500 => InternalException::class,
            default => self::class,
        };

        return new $class(
            errorCode: $code,
            message: $message,
            httpStatus: $httpStatus,
            retryable: $retryable,
            retryAfter: $retryAfter,
            requestId: isset($detail['request_id']) ? (string) $detail['request_id'] : null,
            field: isset($detail['field']) ? (string) $detail['field'] : null,
            synthetic: $synthetic,
            raw: $raw,
        );
    }
}
