<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\PaymentStatus;

/** One invoice spawned by a payment link (`payments` of `PaymentLink`, `info` only). */
final class PaymentLinkPayment
{
    /** @var list<string> */
    public const KEYS = ['uuid', 'amount', 'currency', 'status', 'created_at'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** The invoice's own id (use it in `payments->info()`). */
        public readonly string $uuid,
        /** Priced amount of the invoice. Decimal string. */
        public readonly string $amount,
        /** Invoice currency code. */
        public readonly string $currency,
        /** Invoice lifecycle status. */
        public readonly PaymentStatus $status,
        /** Creation time (RFC 3339). */
        public readonly string $created_at,
        /** Your order number for this invoice, when one was passed. */
        public readonly ?string $order_id = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'uuid'),
            Wire::str($data, 'amount'),
            Wire::str($data, 'currency'),
            Wire::enum(PaymentStatus::class, $data, 'status'),
            Wire::str($data, 'created_at'),
            Wire::nullableStr($data, 'order_id'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
