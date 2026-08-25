<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\AmountMode;

/** `GET /v1/link/{id}` — the payer-facing view of a payment link. */
final class PublicPaymentLink
{
    /** @var list<string> */
    public const KEYS = [
        'link_id', 'title', 'description', 'amount_mode', 'currency', 'amount_fixed', 'pinned_network',
    ];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Payment link id. */
        public readonly string $link_id,
        /** Title shown to the payer. */
        public readonly string $title,
        /** Description shown to the payer. */
        public readonly string $description,
        /** How the link prices its invoices. */
        public readonly AmountMode $amount_mode,
        /** Link currency code. */
        public readonly string $currency,
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
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'link_id'),
            Wire::str($data, 'title'),
            Wire::str($data, 'description'),
            Wire::enum(AmountMode::class, $data, 'amount_mode'),
            Wire::str($data, 'currency'),
            Wire::nullableStr($data, 'amount_fixed'),
            Wire::nullableStr($data, 'min_amount'),
            Wire::nullableStr($data, 'max_amount'),
            Wire::nullableStr($data, 'pinned_currency'),
            Wire::nullableStr($data, 'pinned_network'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
