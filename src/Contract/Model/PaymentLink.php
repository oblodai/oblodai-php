<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\AmountMode;

/** Payment link as `/v1/payment/link/info` and `/list` render it. Amount fields depend on `amount_mode`. */
final class PaymentLink
{
    /**
     * Keys of a `fixed` link with a pinned network — the shape the golden body was recorded with.
     *
     * @var list<string>
     */
    public const KEYS = [
        'link_id', 'url', 'active', 'title', 'description', 'amount_mode', 'currency', 'amount_fixed',
        'pinned_network', 'expires_at', 'document_url', 'created_at',
    ];

    /**
     * @param list<PaymentLinkPayment>|null $payments
     * @param array<string, mixed>          $raw
     */
    public function __construct(
        /** Payment link id. */
        public readonly string $link_id,
        /** Public URL of the payment page. */
        public readonly string $url,
        /** True — the link accepts new payments. */
        public readonly bool $active,
        /** Title shown to the payer. */
        public readonly string $title,
        /** Description shown to the payer. */
        public readonly string $description,
        /** How the link prices its invoices. */
        public readonly AmountMode $amount_mode,
        /** Link currency code. */
        public readonly string $currency,
        /** Signed link to the PDF poster with the payment QR; empty when documents are disabled. */
        public readonly string $document_url,
        /** Creation time (RFC 3339). */
        public readonly string $created_at,
        /** `fixed` links: the amount every invoice is created for. */
        public readonly ?string $amount_fixed = null,
        /** `range` links: the lowest amount the payer may enter. */
        public readonly ?string $min_amount = null,
        /** `range` links: the highest amount the payer may enter. */
        public readonly ?string $max_amount = null,
        /** Currency the payer must pay in, when the link pins one. */
        public readonly ?string $pinned_currency = null,
        /** Network the payer must pay on, when the link pins one. */
        public readonly ?string $pinned_network = null,
        /** When the link stops accepting payments (RFC 3339), if it expires. */
        public readonly ?string $expires_at = null,
        /** `info` only: invoices spawned by this link. */
        public readonly ?array $payments = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $payments = Wire::optionalRows($data, 'payments');

        return new self(
            Wire::str($data, 'link_id'),
            Wire::str($data, 'url'),
            Wire::bool($data, 'active'),
            Wire::str($data, 'title'),
            Wire::str($data, 'description'),
            Wire::enum(AmountMode::class, $data, 'amount_mode'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'document_url'),
            Wire::str($data, 'created_at'),
            Wire::nullableStr($data, 'amount_fixed'),
            Wire::nullableStr($data, 'min_amount'),
            Wire::nullableStr($data, 'max_amount'),
            Wire::nullableStr($data, 'pinned_currency'),
            Wire::nullableStr($data, 'pinned_network'),
            Wire::nullableStr($data, 'expires_at'),
            $payments === null
                ? null
                : array_map(static fn (array $p): PaymentLinkPayment => PaymentLinkPayment::fromArray($p), $payments),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
