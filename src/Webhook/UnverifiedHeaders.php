<?php

declare(strict_types=1);

namespace Oblodai\Webhook;

/**
 * What a delivery's headers said beyond the signature. NONE of these are covered by the MAC (the
 * core signs `<timestamp>.<body>` only): anyone who captured an authentic delivery can replay it
 * with different values here. Log and trace with them; never deduplicate, order or skip on them —
 * use {@see Delivery::$eventKey} and {@see Delivery::$isTest}, which come from the signed body.
 * Header names are the contract's, {@see \Oblodai\Generated\Signing}::HEADER_WEBHOOK_*.
 */
final class UnverifiedHeaders
{
    public function __construct(
        /** Header `HEADER_WEBHOOK_ID` — the delivery (identical across retries of one delivery). */
        public readonly ?string $deliveryId = null,
        /** Header `HEADER_WEBHOOK_EVENT_ID` — the core's id for the state the delivery carries. */
        public readonly ?string $eventId = null,
        /** Header `HEADER_WEBHOOK_EVENT` — the event name (`invoice.paid`, `payout.sent`, …). */
        public readonly ?string $eventType = null,
        /** Header `HEADER_WEBHOOK_EVENT_TIME` — unix seconds when the state change committed. */
        public readonly ?int $eventTime = null,
        /** Header `HEADER_WEBHOOK_TEST` said `true`. Use {@see Delivery::$isTest} instead. */
        public readonly bool $test = false,
    ) {
    }
}
