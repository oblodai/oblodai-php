<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\PaymentStatus;

/** The payer-facing view (`GET /v1/pay/{id}`, `/select`, link checkout): no merchant-only fields. */
final class PublicPayment
{
    /** @var list<string> */
    public const KEYS = [
        'uuid', 'order_id', 'status', 'is_final', 'amount', 'currency', 'network', 'payer_amount',
        'payer_currency', 'amount_paid', 'amount_remaining', 'address', 'destination_tag', 'memo',
        'address_xaddress', 'address_muxed', 'address_qr_code', 'is_multi', 'url', 'url_return',
        'url_success', 'expired_at', 'rate_expires_at', 'confirmations', 'required_confirmations',
        'txid', 'created_at', 'updated_at',
    ];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Our payment id (use it in info/refund). */
        public readonly string $uuid,
        /** Your order number, passed at creation. */
        public readonly string $order_id,
        /** Status: select (choosing a currency) | created | confirm_check | paid | paid_over | wrong_amount | expired | cancelled. */
        public readonly PaymentStatus $status,
        /** True — the status is final and will not change again. */
        public readonly bool $is_final,
        /** Amount due in the price currency (for example, in USD). */
        public readonly string $amount,
        /** Price currency: fiat (USD, EUR, RUB, JPY…) or a coin. Tells what the invoice COSTS. */
        public readonly string $currency,
        /** Blockchain network; empty until the payer selects one on a multi-network invoice. */
        public readonly string $network,
        /** How much must be sent in the payment crypto. */
        public readonly string $payer_amount,
        /** Currency the customer pays in (for example, USDT). Empty on a currency-agnostic invoice. */
        public readonly string $payer_currency,
        /** How much has already been confirmed as paid, in the payment crypto; 0 if nothing arrived. */
        public readonly string $amount_paid,
        /** How much is still left to pay (due − paid); 0 if enough has arrived. */
        public readonly string $amount_remaining,
        /** Address the customer sends the funds to. On XRP the classic r-address of the shared wallet. */
        public readonly string $address,
        /** XRP only: numeric destination tag the customer MUST include. Empty on other networks. */
        public readonly string $destination_tag,
        /** Stellar (XLM) only: the memo the customer MUST include. Empty on other networks. */
        public readonly string $memo,
        /** XRP only: address and tag in one X-address string (XLS-5). */
        public readonly string $address_xaddress,
        /** Stellar only: address and memo in one muxed `M…` string (SEP-23). */
        public readonly string $address_muxed,
        /** Address QR code as a PNG `data:` URI — usable directly in `<img src>`. */
        public readonly string $address_qr_code,
        /** True — a currency-agnostic link; the customer has not chosen a currency/network yet. */
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
        /** Current number of confirmations of the incoming payment. */
        public readonly int $confirmations,
        /** How many confirmations are needed for crediting. */
        public readonly int $required_confirmations,
        /** Hash of the incoming transaction (once it is seen). */
        public readonly string $txid,
        /** Creation time (RFC 3339). */
        public readonly string $created_at,
        /** Time of the last change (RFC 3339). */
        public readonly string $updated_at,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
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
            Wire::int($data, 'confirmations'),
            Wire::int($data, 'required_confirmations'),
            Wire::str($data, 'txid'),
            Wire::str($data, 'created_at'),
            Wire::str($data, 'updated_at'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
