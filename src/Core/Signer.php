<?php

declare(strict_types=1);

namespace Oblodai\Core;

/**
 * Request signing — the exact recipe the core verifies (`crypto.SignRequest`):
 *
 *   canonical = ts "\n" METHOD "\n" requestURI "\n" idempotencyKey "\n" body
 *   signature = hex(HMAC-SHA256(secret, canonical))
 *
 * - `ts` is unix seconds; the core accepts ±300 s of skew.
 * - `requestURI` is path + raw query (`/v1/x?limit=1`), never the origin.
 * - The idempotency slot is the empty string when no `Idempotency-Key` header is sent.
 * - `body` is the byte-exact request body; GETs sign an empty body.
 *
 * Pure: no clock, no I/O. The vectors in tests/Unit/SigningTest.php come from the core test suite.
 */
final class Signer
{
    /** Signed request headers as the core reads them. */
    public const HEADER_PUBLIC_ID = 'X-Public-Id';
    public const HEADER_SIGNATURE = 'X-Signature';
    public const HEADER_TIMESTAMP = 'X-Timestamp';
    public const HEADER_IDEMPOTENCY_KEY = 'Idempotency-Key';

    /** Accepted clock skew on the core side, in seconds. */
    public const SKEW_SECONDS = 300;

    /** The string the signature is taken over. */
    public static function canonical(
        int $ts,
        string $method,
        string $requestUri,
        ?string $idempotencyKey,
        string $body,
    ): string {
        return $ts . "\n" . strtoupper($method) . "\n" . $requestUri . "\n" . ($idempotencyKey ?? '') . "\n" . $body;
    }

    public static function sign(
        string $secret,
        int $ts,
        string $method,
        string $requestUri,
        ?string $idempotencyKey,
        string $body,
    ): string {
        return hash_hmac('sha256', self::canonical($ts, $method, $requestUri, $idempotencyKey, $body), $secret);
    }

    /**
     * Webhook signature — `webhook.Sign` on the core side:
     *
     *   signature = hex(HMAC-SHA256(secret, "<unix ts>." + payload))
     *
     * The payload is signed verbatim, so verifiers must use the raw request bytes, never a
     * re-encoded parse of them.
     */
    public static function signWebhook(string $secret, int $ts, string $payload): string
    {
        return hash_hmac('sha256', $ts . '.' . $payload, $secret);
    }
}
