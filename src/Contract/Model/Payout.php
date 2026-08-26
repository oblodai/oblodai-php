<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\FeeBearerResult;
use Oblodai\Contract\Enum\PayoutStatus;

/**
 * Payout as `/v1/payout`, `/info`, `/history`, `/cancel`, mass/batch elements and refunds render
 * it (core `PayoutResult`). `error`/`error_code` appear on `info` for failed payouts.
 */
final class Payout
{
    /** Wire keys every rendering of a payout carries. @var list<string> */
    public const KEYS = [
        'uuid', 'order_id', 'status', 'is_final', 'amount', 'currency', 'network', 'address', 'memo',
        'payer_amount', 'commission', 'fee_bearer', 'source', 'approval_required', 'is_refund',
        'refund_for', 'payment_order_id', 'txid', 'document_url', 'created_at', 'updated_at',
    ];

    /**
     * @param array<string, mixed> $raw
     * @param OpenEnum<PayoutStatus> $status
     * @param OpenEnum<FeeBearerResult> $fee_bearer
     */
    public function __construct(
        /** Payout id. */
        public readonly string $uuid,
        /** Your payout number (reference). Null for a refund: see `payment_order_id` instead. */
        public readonly ?string $order_id,
        /**
         * Lifecycle: pending → approved → awaiting_cosign → broadcasting → sent → confirmed |
         * failed | cancelled. (The public docs group these into coarser names — check, process,
         * paid — but the wire carries the values above, which is what this enum holds.)
         */
        public readonly OpenEnum $status,
        /** True — the status is final (paid / fail / cancel). */
        public readonly bool $is_final,
        /** Payout amount in currency, debited from your balance. Decimal string. */
        public readonly string $amount,
        /** Payout currency code. */
        public readonly string $currency,
        /** Blockchain network. */
        public readonly string $network,
        /** Recipient address. */
        public readonly string $address,
        /** Destination tag/memo passed at creation (TON Jetton, exchange memo). Empty — no memo. */
        public readonly string $memo,
        /** How much actually goes to the recipient's address: amount minus commission. */
        public readonly string $payer_amount,
        /** Network fee withheld, in the payout currency. 0 — the gateway absorbed the fee. */
        public readonly string $commission,
        /** Who paid the network fee: gateway (absorbed it), merchant, or recipient. */
        public readonly OpenEnum $fee_bearer,
        /** api (via the integration) | manual (from the cabinet). */
        public readonly string $source,
        /** True — the payout is awaiting approval (internal scenarios; always false for an API key). */
        public readonly bool $approval_required,
        /** True — this is a payment refund, not a regular payout. */
        public readonly bool $is_refund,
        /** Id of the payment being refunded (null if this is not a refund). */
        public readonly ?string $refund_for,
        /** Your order_id of the payment the refund was made for (null for a regular payout). */
        public readonly ?string $payment_order_id,
        /** On-chain transaction hash (appears after sending). */
        public readonly string $txid,
        /** Signed link to the PDF cheque for this operation; empty when documents are disabled. */
        public readonly string $document_url,
        /** Creation time (RFC 3339). */
        public readonly string $created_at,
        /** Time of the last change (RFC 3339). */
        public readonly string $updated_at,
        /** `info` only: set on a failed payout. */
        public readonly ?string $error = null,
        /** `info` only: machine-readable failure reason. */
        public readonly ?string $error_code = null,
        /** Set on refunds of blocked static-wallet deposits. */
        public readonly ?string $wallet_uuid = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
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
            Wire::nullableStr($data, 'error'),
            Wire::nullableStr($data, 'error_code'),
            Wire::nullableStr($data, 'wallet_uuid'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
