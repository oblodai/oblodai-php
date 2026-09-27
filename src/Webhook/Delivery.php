<?php

declare(strict_types=1);

namespace Oblodai\Webhook;

/**
 * A verified delivery: the signed event, the dedupe key and test flag derived from it, the send
 * time, and — under `$unverified` — the header values the signature does not cover.
 */
final class Delivery
{
    public readonly UnverifiedHeaders $unverified;

    /** @param array<string, mixed> $event */
    public function __construct(
        /**
         * The verified body — a JSON object with a string `type`. {@see Verifier::model()} turns it
         * into the generated model of its kind (`PaymentWebhook`, `PayoutWebhook`, …).
         */
        public readonly array $event,
        /**
         * The deduplication key, from the signed body only: `<type>:<objectId>:<sequence>`
         * ({@see Verifier::eventKey()}). The same for the original, every retry and every resend of
         * one state; different once the state changes. Null for a kind this SDK does not model or a
         * body without an object id or `sequence` — acknowledge such a delivery, do not act on it.
         */
        public readonly ?string $eventKey = null,
        /** Header `HEADER_WEBHOOK_TIMESTAMP` — unix seconds when this attempt was sent (covered by the MAC). */
        public readonly int $sentAt = 0,
        /**
         * A rehearsal delivery: the signed body says `test: true`. Signed exactly like a live one,
         * but no money moved — ALWAYS acknowledge and ignore it. Taken from the body only: the
         * rehearsal header is not signed, so it can neither make a live delivery look like a test
         * nor the other way round.
         */
        public readonly bool $isTest = false,
        ?UnverifiedHeaders $unverified = null,
    ) {
        $this->unverified = $unverified ?? new UnverifiedHeaders();
    }
}
