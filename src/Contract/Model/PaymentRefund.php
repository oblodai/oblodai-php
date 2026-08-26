<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\PayoutStatus;

/** A refund issued against an invoice (a payout in disguise; full detail via `payouts->info()`). */
final class PaymentRefund
{
    /** @var list<string> */
    public const KEYS = ['uuid', 'address', 'amount', 'status', 'is_final', 'txid', 'created_at'];

    /**
     * @param array<string, mixed> $raw
     * @param OpenEnum<PayoutStatus> $status
     */
    public function __construct(
        /** The refund payout's id. */
        public readonly string $uuid,
        /** Where the money was sent. */
        public readonly string $address,
        /** Refunded amount, decimal string. */
        public readonly string $amount,
        public readonly OpenEnum $status,
        /** True — the status is final and will not change again. */
        public readonly bool $is_final,
        /** Hash of the outgoing transaction, once broadcast. */
        public readonly string $txid,
        public readonly string $created_at,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'uuid'),
            Wire::str($data, 'address'),
            Wire::str($data, 'amount'),
            Wire::enum(PayoutStatus::class, $data, 'status'),
            Wire::bool($data, 'is_final'),
            Wire::str($data, 'txid'),
            Wire::str($data, 'created_at'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
