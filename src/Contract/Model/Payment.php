<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\PaymentStatus;

/**
 * Invoice as `/v1/payment`, `/v1/payment/info`, `/v1/payment/history` and `/v1/payment/cancel`
 * render it (core `paymentResult`). `refunds`/`refund_status` are present on `info` only.
 */
final class Payment
{
    /** Wire keys every rendering of an invoice carries. @var list<string> */
    public const KEYS = [
        'uuid', 'order_id', 'status', 'is_final', 'amount', 'currency', 'network', 'payer_amount',
        'payer_currency', 'amount_paid', 'amount_remaining', 'address', 'destination_tag', 'memo',
        'address_xaddress', 'address_muxed', 'address_qr_code', 'is_multi', 'url', 'url_return',
        'url_success', 'expired_at', 'rate_expires_at', 'exchange_rate', 'confirmations',
        'required_confirmations', 'txid', 'tx_list', 'paid_at', 'payer_address',
        'payer_address_is_refundable', 'payer_email', 'additional_data', 'commission',
        'merchant_amount', 'document_url', 'is_test', 'created_at', 'updated_at',
    ];

    /**
     * @param list<PaymentTx>          $tx_list
     * @param list<PaymentRefund>|null $refunds
     * @param array<string, mixed>     $raw
     */
    public function __construct(
        /** Our payment id (use it in info/refund). */
        public readonly string $uuid,
        /** Your order number, passed at creation. */
        public readonly string $order_id,
        /** Where the invoice stands; `wrong_amount` needs `refunds->resolve()`. */
        public readonly PaymentStatus $status,
        /** True — the status is final and will not change again. */
        public readonly bool $is_final,
        /** Amount due in the price currency (for example, in USD). Decimal string. */
        public readonly string $amount,
        /** Price currency: fiat or a coin. Tells what the invoice COSTS, not what it is paid with. */
        public readonly string $currency,
        /** Settlement network; empty until the payer selects one on a multi-network invoice. */
        public readonly string $network,
        /** How much must be sent in the payment crypto. */
        public readonly string $payer_amount,
        /** Currency the customer pays in (for example, USDT). Empty on a currency-agnostic invoice. */
        public readonly string $payer_currency,
        /** How much has already been confirmed as paid, in the payment crypto. */
        public readonly string $amount_paid,
        /** How much is still left to pay (due − paid); 0 if enough has arrived. */
        public readonly string $amount_remaining,
        /** Address the customer sends the funds to. On XRP the classic r-address of the shared wallet. */
        public readonly string $address,
        /** XRP only: numeric destination tag the customer MUST include. Empty on other networks. */
        public readonly string $destination_tag,
        /** Stellar/TON only: the memo the customer MUST include. Empty on other networks. */
        public readonly string $memo,
        /** XRP only: address and tag in one X-address string (XLS-5). */
        public readonly string $address_xaddress,
        /** Stellar only: address and memo in one muxed `M…` string (SEP-23). */
        public readonly string $address_muxed,
        /** Address QR code as a PNG `data:` URI — usable directly in `<img src>`. */
        public readonly string $address_qr_code,
        /** True — a currency-agnostic invoice; the customer has not chosen a currency/network yet. */
        public readonly bool $is_multi,
        /** Link to the hosted payment page. */
        public readonly string $url,
        /** "Back to shop" link shown before payment. */
        public readonly string $url_return,
        /** Where to redirect after successful payment. */
        public readonly string $url_success,
        /** When the invoice expires (RFC 3339, like all time fields). */
        public readonly string $expired_at,
        /** When the rate will be refreshed (the rate holds for ~5 min). */
        public readonly string $rate_expires_at,
        /** Rate locked by this invoice; `payer_amount` is calculated from it. */
        public readonly string $exchange_rate,
        /** Current number of confirmations of the incoming payment. */
        public readonly int $confirmations,
        /** How many confirmations are needed for crediting. */
        public readonly int $required_confirmations,
        /** Hash of the incoming transaction (once it is seen). */
        public readonly string $txid,
        /** All confirmed transfers that paid the invoice. */
        public readonly array $tx_list,
        /** Moment of the actual payment; null until the payment arrives. */
        public readonly ?string $paid_at,
        /** Address the first confirmed deposit came FROM; not necessarily refundable. */
        public readonly string $payer_address,
        /** True — `payer_address` belongs to the payer and a refund may omit `address`. */
        public readonly bool $payer_address_is_refundable,
        /** Payer e-mail, if you passed one. */
        public readonly string $payer_email,
        /** Your private data, returned in the response and in the webhook. */
        public readonly string $additional_data,
        /** Our commission on this payment, in the payment currency. */
        public readonly string $commission,
        /** How much is (or will be) credited to you: `amount_paid − commission`. */
        public readonly string $merchant_amount,
        /** Signed link to the PDF cheque for this operation; empty when documents are disabled. */
        public readonly string $document_url,
        /** True — a sandbox invoice: the money is not real. */
        public readonly bool $is_test,
        /** Creation time (RFC 3339). */
        public readonly string $created_at,
        /** Time of the last change (RFC 3339). */
        public readonly string $updated_at,
        /** `info` only: refunds issued against this invoice. */
        public readonly ?array $refunds = null,
        /** `info` only: `none` | `partial` | `full`. */
        public readonly ?string $refund_status = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $refunds = Wire::optionalRows($data, 'refunds');

        return new self(
            Wire::str($data, 'uuid'),
            Wire::str($data, 'order_id'),
            Wire::enum(PaymentStatus::class, $data, 'status'),
            Wire::bool($data, 'is_final'),
            Wire::str($data, 'amount'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'network'),
            Wire::str($data, 'payer_amount'),
            Wire::str($data, 'payer_currency'),
            Wire::str($data, 'amount_paid'),
            Wire::str($data, 'amount_remaining'),
            Wire::str($data, 'address'),
            Wire::str($data, 'destination_tag'),
            Wire::str($data, 'memo'),
            Wire::str($data, 'address_xaddress'),
            Wire::str($data, 'address_muxed'),
            Wire::str($data, 'address_qr_code'),
            Wire::bool($data, 'is_multi'),
            Wire::str($data, 'url'),
            Wire::str($data, 'url_return'),
            Wire::str($data, 'url_success'),
            Wire::str($data, 'expired_at'),
            Wire::str($data, 'rate_expires_at'),
            Wire::str($data, 'exchange_rate'),
            Wire::int($data, 'confirmations'),
            Wire::int($data, 'required_confirmations'),
            Wire::str($data, 'txid'),
            array_map(
                static fn (array $tx): PaymentTx => PaymentTx::fromArray($tx),
                Wire::rows($data, 'tx_list')
            ),
            Wire::nullableStr($data, 'paid_at'),
            Wire::str($data, 'payer_address'),
            Wire::bool($data, 'payer_address_is_refundable'),
            Wire::str($data, 'payer_email'),
            Wire::str($data, 'additional_data'),
            Wire::str($data, 'commission'),
            Wire::str($data, 'merchant_amount'),
            Wire::str($data, 'document_url'),
            Wire::bool($data, 'is_test'),
            Wire::str($data, 'created_at'),
            Wire::str($data, 'updated_at'),
            $refunds === null
                ? null
                : array_map(static fn (array $r): PaymentRefund => PaymentRefund::fromArray($r), $refunds),
            Wire::nullableStr($data, 'refund_status'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
