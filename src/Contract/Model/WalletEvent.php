<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `wallet.paid` — a deposit landed on a static wallet. */
final class WalletEvent implements WebhookEvent
{
    /** @var list<string> */
    public const KEYS = [
        'type', 'uuid', 'order_id', 'status', 'is_final', 'address', 'currency', 'network',
        'payer_currency', 'payment_amount', 'txid', 'event_at', 'sequence',
    ];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Always `"wallet"`. */
        public readonly string $type,
        /** The wallet event's id. */
        public readonly string $uuid,
        /** Merchant reference, when the static wallet was created for one. */
        public readonly ?string $order_id,
        /** Always `"paid"`. */
        public readonly string $status,
        /** True — the status is final and will not change again. */
        public readonly bool $is_final,
        /** Static wallet address the deposit landed on. */
        public readonly string $address,
        /** Asset credited. */
        public readonly string $currency,
        /** Network the deposit arrived on. */
        public readonly string $network,
        /** Currency the customer actually paid in. */
        public readonly string $payer_currency,
        /** Amount credited, in `payer_currency`. */
        public readonly string $payment_amount,
        /** Hash of the incoming transaction. */
        public readonly string $txid,
        /** When the state change was committed — order events by this, or by `sequence`. */
        public readonly string $event_at,
        /**
         * Global, increasing (gaps are normal); a lower sequence arriving later is stale. Null when
         * the core sent no sequence at all — such an event is never reported as stale.
         */
        public readonly ?int $sequence,
        /**
         * Present and true ONLY on rehearsal deliveries (`webhooks.test`, sandbox). The body is
         * signed like a live one, so a handler must check this flag (or the `X-Webhook-Test`
         * header) and never act on a test event as if money moved.
         */
        public readonly ?bool $test = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'type', 'wallet'),
            Wire::str($data, 'uuid'),
            Wire::nullableStr($data, 'order_id'),
            Wire::str($data, 'status', 'paid'),
            Wire::bool($data, 'is_final'),
            Wire::str($data, 'address'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'network'),
            Wire::str($data, 'payer_currency'),
            Wire::str($data, 'payment_amount'),
            Wire::str($data, 'txid'),
            Wire::str($data, 'event_at'),
            Wire::nullableInt($data, 'sequence'),
            Wire::nullableBool($data, 'test'),
            $data,
        );
    }

    public function type(): string
    {
        return $this->type;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }

    public function sequence(): ?int
    {
        return $this->sequence;
    }

    public function isFinal(): bool
    {
        return $this->is_final;
    }

    public function isTest(): bool
    {
        return $this->test === true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
