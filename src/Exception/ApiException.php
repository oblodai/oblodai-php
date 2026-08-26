<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/**
 * The core (or something in front of it) answered with an error status.
 *
 * The envelope is read field by field, never all-or-nothing: a gateway that mangles one field must
 * not cost the caller the other five. Anything of an unexpected type is treated as absent and the
 * documented default takes over, so decoding an error can itself never throw.
 */
class ApiException extends OblodaiException
{
    /** Statuses a response without an envelope may carry transiently (LB/proxy/timeouts). */
    private const TRANSIENT_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    /**
     * Build the right subclass from an error envelope (or a synthesized one) and the HTTP status.
     *
     * @param array<string, mixed> $detail the `error` object as received; any field may be missing
     *                                     or of the wrong type
     */
    public static function from(
        int $httpStatus,
        array $detail,
        mixed $raw = null,
        bool $synthetic = false,
        ?int $retryAfterHeader = null,
    ): self {
        $code = self::stringOrNull($detail['code'] ?? null);
        if ($code === null || $code === '') {
            // No usable code means no usable envelope, whatever else the body contained.
            $code = 'internal';
            $synthetic = true;
        }
        $message = self::stringOrNull($detail['message'] ?? null)
            ?? sprintf('HTTP %d', $httpStatus);
        $retryable = $synthetic
            ? in_array($httpStatus, self::TRANSIENT_STATUSES, true)
            : (is_bool($detail['retryable'] ?? null)
                ? $detail['retryable']
                : ($httpStatus === 429 || $httpStatus === 503));
        $retryAfter = self::retryAfterSeconds($detail['retry_after'] ?? null) ?? $retryAfterHeader;

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
            requestId: self::stringOrNull($detail['request_id'] ?? null),
            field: self::stringOrNull($detail['field'] ?? null),
            synthetic: $synthetic,
            raw: $raw,
        );
    }

    /** A string field, or null when the core sent something that is not one. */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * `retry_after` as whole seconds: an integer, a float or a numeric string. Anything else is
     * absent. The value is clamped into [0, MAX_RETRY_AFTER_SECONDS] in float space before it ever
     * becomes an int, so neither a negative nor a 1e30 can overflow the conversion.
     */
    public static function retryAfterSeconds(mixed $value): ?int
    {
        if (is_bool($value) || (!is_int($value) && !is_float($value) && !is_string($value))) {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !is_numeric($value)) {
                return null;
            }
        }
        $seconds = (float) $value;
        if (is_nan($seconds)) {
            return null;
        }

        return (int) max(0.0, min((float) OblodaiException::MAX_RETRY_AFTER_SECONDS, $seconds));
    }
}
