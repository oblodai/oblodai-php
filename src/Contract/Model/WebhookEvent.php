<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/**
 * A delivered webhook event: `invoice.<status>` ({@see PaymentEvent}), `payout.<status>`
 * ({@see PayoutEvent}), or `wallet.paid` ({@see WalletEvent}). Decode a raw wire body with
 * {@see WebhookEventFactory::fromArray()}.
 */
interface WebhookEvent
{
    /** The discriminant: `payment` | `payout` | `wallet`. */
    public function type(): string;

    /** The subject id (invoice, payout or wallet-event uuid). */
    public function uuid(): string;

    /**
     * Global, increasing sequence (gaps are normal); a lower sequence arriving later is stale.
     * Null when the body carries none — an event without a sequence is never treated as stale.
     */
    public function sequence(): ?int;

    /** True — the status is final and will not change again. */
    public function isFinal(): bool;

    /**
     * True on a rehearsal delivery (`webhooks.test`, sandbox): the body is signed like a live one,
     * but no money moved — never act on it as if it did.
     */
    public function isTest(): bool;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
