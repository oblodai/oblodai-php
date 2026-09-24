<?php

declare(strict_types=1);

namespace Oblodai\Core;

/**
 * Overrides for one call — the last argument of every resource method. Every field left null falls
 * back to the client's setting.
 *
 * ```php
 * $oblodai->payouts->create($payout, new RequestOptions(idempotencyKey: 'payout-42', timeout: 10));
 * ```
 */
final class RequestOptions
{
    /**
     * @param array<string, string> $extraHeaders headers for this call only, merged over the
     *                                            client's own; the SDK's own (signature,
     *                                            idempotency, content type, request id, admin
     *                                            token) can never be overridden, whatever the casing
     */
    public function __construct(
        /**
         * Your own idempotency key; one is generated automatically on routes the gateway
         * deduplicates, and a key is rejected on routes it does not.
         */
        public readonly ?string $idempotencyKey = null,
        /** Per-attempt timeout in seconds (capped by the client's deadline for the whole call). */
        public readonly int|float|null $timeout = null,
        /** Retries after the first attempt, for this call; overrides `Retry::$maxRetries`. */
        public readonly ?int $maxRetries = null,
        /** Extra headers for this call alone. */
        public readonly array $extraHeaders = [],
        /** Sent as `X-Request-ID` to tie your logs to ours; a UUID is generated when omitted. */
        public readonly ?string $requestId = null,
    ) {
    }

    /** The same options without the idempotency key. */
    public function withoutIdempotencyKey(): self
    {
        return new self(null, $this->timeout, $this->maxRetries, $this->extraHeaders, $this->requestId);
    }
}
