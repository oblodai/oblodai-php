<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `POST /v1/payment/resolve` with `action: "accept"` — the underpayment was kept as full settlement. */
final class ResolutionAccepted
{
    /** @var list<string> */
    public const KEYS = ['resolution', 'payment_uuid', 'order_id', 'currency', 'amount_kept'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Always `"accepted"`. */
        public readonly string $resolution,
        /** The invoice this resolution applies to. */
        public readonly string $payment_uuid,
        /** The invoice's order_id. */
        public readonly string $order_id,
        /** Price currency of the invoice. */
        public readonly string $currency,
        /** Amount kept as full settlement despite the underpayment. */
        public readonly string $amount_kept,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'resolution'),
            Wire::str($data, 'payment_uuid'),
            Wire::str($data, 'order_id'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'amount_kept'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
