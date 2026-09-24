<?php

declare(strict_types=1);

namespace Oblodai\Webhook;

/** A verified delivery: the event plus the advisory headers worth keeping. */
final class Delivery
{
    /** @param array<string, mixed> $event */
    public function __construct(
        /**
         * The verified body — a JSON object with a string `type`. {@see Verifier::model()} turns it
         * into `PaymentWebhook`, `PayoutWebhook`, `WalletWebhook` or `ConversionWebhook`.
         */
        public readonly array $event,
        /**
         * `X-Webhook-Id` — stable across retries of the same DELIVERY. Not enough to deduplicate
         * on: a resend is a new delivery of a state you may have handled. Use `$eventId`.
         */
        public readonly ?string $id = null,
        /** `X-Webhook-Event` — `invoice.<status>` | `payout.<status>` | `wallet.paid` | `conversion.*`. */
        public readonly ?string $eventType = null,
        /** `X-Webhook-Event-Time` — unix seconds when the state change committed. */
        public readonly ?int $eventTime = null,
        /** `X-Webhook-Timestamp` — unix seconds when this attempt was sent. */
        public readonly int $sentAt = 0,
        /**
         * A rehearsal delivery (`X-Webhook-Test: true`, or `test: true` in the signed body):
         * signed exactly like a live one, but no money moved — never act on it as if it did.
         */
        public readonly bool $isTest = false,
        /**
         * `X-Webhook-Event-Id` — the id of the STATE this delivery carries: the same for the
         * original, its retries and every resend of that state. Keep the ids you handled and skip
         * repeats. Null from a gateway older than 2026-09-20.
         */
        public readonly ?string $eventId = null,
    ) {
    }
}
