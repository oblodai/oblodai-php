<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\PaymentStatus;

/** `invoice.<status>` — an invoice changed state (payload of a delivered webhook). */
final class PaymentEvent implements WebhookEvent
{
    /** @var list<string> */
    public const KEYS = [
        'type', 'uuid', 'order_id', 'status', 'is_final', 'amount', 'currency', 'network',
        'payer_amount', 'payer_currency', 'payment_amount', 'payer_address',
        'payer_address_is_refundable', 'additional_data', 'txid', 'event_at', 'sequence',
    ];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Always `"payment"`. */
        public readonly string $type,
        /** Our payment id (use it in info/refund). */
        public readonly string $uuid,
        /** Your order number, passed at creation. */
        public readonly ?string $order_id,
        /** Where the invoice stands after this change. */
        public readonly PaymentStatus $status,
        /** True — the status is final and will not change again. */
        public readonly bool $is_final,
        /** Amount due in the price currency (for example, in USD). */
        public readonly string $amount,
        /** Price currency: fiat or a coin. Tells what the invoice COSTS, not what it is paid with. */
        public readonly string $currency,
        /** Settlement network; empty until the payer selects one on a multi-network invoice. */
        public readonly string $network,
        /** How much must be sent in the payment crypto. */
        public readonly string $payer_amount,
        /** Currency the customer pays in (for example, USDT). Empty on a currency-agnostic invoice. */
        public readonly string $payer_currency,
        /** What actually landed on the address, in `payer_currency`. */
        public readonly string $payment_amount,
        /** Address the first confirmed deposit came FROM; not necessarily refundable. */
        public readonly string $payer_address,
        /** True — `payer_address` belongs to the payer and a refund may omit `address`. */
        public readonly bool $payer_address_is_refundable,
        /** Your private data, returned in the response and in the webhook. */
        public readonly string $additional_data,
        /** Hash of the incoming transaction (once it is seen). */
        public readonly string $txid,
        /** When the state change was committed — order events by this, or by `sequence`. */
        public readonly string $event_at,
        /** Global, increasing (gaps are normal); a lower sequence arriving later is stale. */
        public readonly int $sequence,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'type', 'payment'),
            Wire::str($data, 'uuid'),
            Wire::nullableStr($data, 'order_id'),
            Wire::enum(PaymentStatus::class, $data, 'status'),
            Wire::bool($data, 'is_final'),
            Wire::str($data, 'amount'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'network'),
            Wire::str($data, 'payer_amount'),
            Wire::str($data, 'payer_currency'),
            Wire::str($data, 'payment_amount'),
            Wire::str($data, 'payer_address'),
            Wire::bool($data, 'payer_address_is_refundable'),
            Wire::str($data, 'additional_data'),
            Wire::str($data, 'txid'),
            Wire::str($data, 'event_at'),
            Wire::int($data, 'sequence'),
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

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function isFinal(): bool
    {
        return $this->is_final;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
