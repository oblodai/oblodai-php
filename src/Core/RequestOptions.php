<?php

declare(strict_types=1);

namespace Oblodai\Core;

/** Per-call knobs every resource method accepts as its last argument. */
final class RequestOptions
{
    /**
     * @param array<string, string> $headers extra headers for this call only; the SDK's own
     *                                       (signature, idempotency, content type, admin token) can
     *                                       never be overridden, whatever the casing
     */
    public function __construct(
        /**
         * Your own idempotency key; one is generated automatically on create routes when omitted.
         * Rejected on routes the core does not deduplicate.
         */
        public readonly ?string $idempotencyKey = null,
        /** Per-attempt timeout, ms. Defaults to the client's. */
        public readonly ?int $timeoutMs = null,
        /** Overall budget for this call including retries and pauses, ms. Defaults to the client's. */
        public readonly ?int $deadlineMs = null,
        /** Extra headers for this call only, merged over the client's own. */
        public readonly array $headers = [],
    ) {
    }

    /** List pages must never carry the caller's key: the core would replay page 1 forever. */
    public function withoutIdempotencyKey(): self
    {
        return new self(null, $this->timeoutMs, $this->deadlineMs, $this->headers);
    }
}
