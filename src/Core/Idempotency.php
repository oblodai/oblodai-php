<?php

declare(strict_types=1);

namespace Oblodai\Core;

use Oblodai\Exception\ValidationException;

/**
 * Idempotency keys. On create-type routes the core caches the first response per key for the
 * merchant and replays it on retries; a different body under the same key is a 409
 * `idempotency.key_reused`. The SDK generates a key once per logical call and reuses it on every
 * retry, so a timeout never turns into a double payout.
 */
final class Idempotency
{
    public const MAX_KEY_LENGTH = 255;
    public const BAD_KEY = 'sdk.bad_idempotency_key';

    public static function newKey(): string
    {
        return Util::uuid4();
    }

    /** Validate a caller-supplied key before it is signed and sent. */
    public static function assertKey(string $key): void
    {
        if ($key === '') {
            throw self::invalid('idempotencyKey must be a non-empty string');
        }
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw self::invalid(sprintf('idempotencyKey is too long (max %d chars)', self::MAX_KEY_LENGTH));
        }
        // Header values must be visible ASCII: the key is signed verbatim, so a stray control char
        // or surrounding whitespace would silently change the MAC on one side only.
        if (preg_match('/^[\x21-\x7e]+$/', $key) !== 1) {
            throw self::invalid('idempotencyKey must be printable ASCII without spaces');
        }
    }

    private static function invalid(string $message): ValidationException
    {
        return new ValidationException(
            errorCode: self::BAD_KEY,
            message: $message,
            httpStatus: 0,
            retryable: false,
            field: 'idempotencyKey',
        );
    }
}
