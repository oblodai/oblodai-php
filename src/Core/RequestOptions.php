<?php

declare(strict_types=1);

namespace Oblodai\Core;

/** Per-call knobs every resource method accepts as its last argument. */
final class RequestOptions
{
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
        /** Sign with the payout key on a route that accepts either kind (e.g. `batches.info`). */
        public readonly bool $preferPayoutKey = false,
    ) {
    }

    public function withPreferPayoutKey(bool $prefer = true): self
    {
        return new self($this->idempotencyKey, $this->timeoutMs, $this->deadlineMs, $prefer);
    }

    /** List pages must never carry the caller's key: the core would replay page 1 forever. */
    public function withoutIdempotencyKey(): self
    {
        return new self(null, $this->timeoutMs, $this->deadlineMs, $this->preferPayoutKey);
    }
}
