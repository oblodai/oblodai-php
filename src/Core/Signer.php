<?php

declare(strict_types=1);

namespace Oblodai\Core;

use Oblodai\Generated\Signing;

/**
 * Request signing — the exact recipe the core verifies (`crypto.SignRequest`), as the contract's
 * `x-oblodai-signing` states it (generated into {@see Signing}):
 *
 *   canonical = the parts of Signing::REQUEST_CANONICAL_ORDER (ts, METHOD, request_uri,
 *               idempotency_key, body) joined by Signing::REQUEST_CANONICAL_SEPARATOR
 *   signature = hex(HMAC-SHA256(secret, canonical))
 *
 * - `ts` is unix seconds; the core accepts {@see Signing::SKEW_SECONDS} of skew.
 * - `request_uri` is path + raw query (`/v1/x?limit=1`), never the origin.
 * - The idempotency slot is the empty string when no idempotency key header is sent.
 * - `body` is the byte-exact request body; GETs sign an empty body.
 *
 * Pure: no clock, no I/O. The vectors come from the contract (tests/Conformance).
 */
final class Signer
{
    /** Signed request headers as the core reads them: the contract's, {@see Signing}. */
    public const HEADER_PUBLIC_ID = Signing::HEADER_PUBLIC_ID;
    public const HEADER_SIGNATURE = Signing::HEADER_SIGNATURE;
    public const HEADER_TIMESTAMP = Signing::HEADER_TIMESTAMP;
    public const HEADER_IDEMPOTENCY_KEY = Signing::HEADER_IDEMPOTENCY_KEY;

    /** Accepted clock skew on the core side, in seconds. */
    public const SKEW_SECONDS = Signing::SKEW_SECONDS;

    /** The string the signature is taken over. */
    public static function canonical(
        int $ts,
        string $method,
        string $requestUri,
        ?string $idempotencyKey,
        string $body,
    ): string {
        return self::join(Signing::REQUEST_CANONICAL_ORDER, Signing::REQUEST_CANONICAL_SEPARATOR, [
            'ts' => (string) $ts,
            'METHOD' => strtoupper($method),
            'request_uri' => $requestUri,
            'idempotency_key' => $idempotencyKey ?? '',
            'body' => $body,
        ]);
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
     *   signature = hex(HMAC-SHA256(secret, the parts of Signing::WEBHOOK_CANONICAL_ORDER (ts, payload)
     *               joined by Signing::WEBHOOK_CANONICAL_SEPARATOR))
     *
     * The payload is signed verbatim, so verifiers must use the raw request bytes, never a
     * re-encoded parse of them.
     */
    public static function signWebhook(string $secret, int $ts, string $payload): string
    {
        $canonical = self::join(Signing::WEBHOOK_CANONICAL_ORDER, Signing::WEBHOOK_CANONICAL_SEPARATOR, [
            'ts' => (string) $ts,
            'payload' => $payload,
        ]);

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * The canonical string: the named parts in the contract's order. The generator admits only
     * the parts it knows, so every name in `$order` has a value.
     *
     * @param list<string>          $order
     * @param array<string, string> $parts
     */
    private static function join(array $order, string $separator, array $parts): string
    {
        return implode($separator, array_map(static fn (string $part): string => $parts[$part], $order));
    }
}
