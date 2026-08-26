<?php

declare(strict_types=1);

namespace Oblodai\Webhook;

use Oblodai\Contract\Model\WebhookEvent;

/** A verified delivery: the event plus the advisory headers worth keeping. */
final class Delivery
{
    public function __construct(
        /** The parsed event — a PaymentEvent, PayoutEvent or WalletEvent. */
        public readonly WebhookEvent $event,
        /** `X-Webhook-Id` — stable across retries of the same delivery; use it to deduplicate. */
        public readonly ?string $id = null,
        /** `X-Webhook-Event` — `invoice.<status>` | `payout.<status>` | `wallet.paid`. */
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
    ) {
    }
}
