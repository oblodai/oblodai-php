<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\FeeBearerResult;
use Oblodai\Contract\Enum\PayoutStatus;

/** `payout.<status>` — a payout (or refund) changed state; the body is the payout itself. */
final class PayoutEvent implements WebhookEvent
{
    /** @var list<string> */
    public const KEYS = [
        'type', 'uuid', 'order_id', 'status', 'is_final', 'amount', 'currency', 'network',
        'address', 'memo', 'payer_amount', 'commission', 'fee_bearer', 'source',
        'approval_required', 'is_refund', 'refund_for', 'payment_order_id', 'txid',
        'document_url', 'created_at', 'updated_at', 'event_at', 'sequence',
    ];

    /**
     * @param array<string, mixed> $raw
     * @param OpenEnum<PayoutStatus> $status
     * @param OpenEnum<FeeBearerResult> $fee_bearer
     */
    public function __construct(
        /** Always `"payout"`. */
        public readonly string $type,
        /** The payout's id. */
        public readonly string $uuid,
        /** Merchant reference; null for refunds (they are keyed by `reference`/`refund_for`). */
        public readonly ?string $order_id,
        /** Where the payout stands after this change. */
        public readonly OpenEnum $status,
        /** True — the status is final and will not change again. */
        public readonly bool $is_final,
        /** Amount sent, decimal string. */
        public readonly string $amount,
        public readonly string $currency,
        /** Settlement network. */
        public readonly string $network,
        /** Where the money was sent. */
        public readonly string $address,
        /** XRP tag / Stellar memo / TON memo, when the network needs one. */
        public readonly string $memo,
        /** Total debited from the balance (amount plus commission when the merchant bears the fee). */
        public readonly string $payer_amount,
        /** Our commission on this payout. */
        public readonly string $commission,
        /** Who actually bore the network fee. */
        public readonly OpenEnum $fee_bearer,
        /** Balance the payout was funded from (`business` | `personal`). */
        public readonly string $source,
        /** True — a human must approve before broadcasting. */
        public readonly bool $approval_required,
        /** True — this payout is a refund of an invoice. */
        public readonly bool $is_refund,
        /** For refunds: the invoice being refunded. */
        public readonly ?string $refund_for,
        /** The refunded (or split) invoice's order_id, when applicable. */
        public readonly ?string $payment_order_id,
        /** Hash of the outgoing transaction, once broadcast. */
        public readonly string $txid,
        /** Signed link to the PDF cheque for this operation; empty when documents are disabled. */
        public readonly string $document_url,
        /** Creation time (RFC 3339). */
        public readonly string $created_at,
        /** Time of the last change (RFC 3339). */
        public readonly string $updated_at,
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
            Wire::str($data, 'type', 'payout'),
            Wire::str($data, 'uuid'),
            Wire::nullableStr($data, 'order_id'),
            Wire::enum(PayoutStatus::class, $data, 'status'),
            Wire::bool($data, 'is_final'),
            Wire::str($data, 'amount'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'network'),
            Wire::str($data, 'address'),
            Wire::str($data, 'memo'),
            Wire::str($data, 'payer_amount'),
            Wire::str($data, 'commission'),
            Wire::enum(FeeBearerResult::class, $data, 'fee_bearer'),
            Wire::str($data, 'source'),
            Wire::bool($data, 'approval_required'),
            Wire::bool($data, 'is_refund'),
            Wire::nullableStr($data, 'refund_for'),
            Wire::nullableStr($data, 'payment_order_id'),
            Wire::str($data, 'txid'),
            Wire::str($data, 'document_url'),
            Wire::str($data, 'created_at'),
            Wire::str($data, 'updated_at'),
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
