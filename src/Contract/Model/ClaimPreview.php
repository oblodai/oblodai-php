<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\FeeBearer;
use Oblodai\Contract\Enum\PayoutLinkStatus;

/** `GET /v1/claim/{token}` — what the recipient sees before claiming. */
final class ClaimPreview
{
    /** @var list<string> */
    public const KEYS = [
        'status', 'claimable', 'amount', 'currency', 'network', 'commission', 'payer_amount',
        'fee_bearer', 'fee_type', 'title', 'note', 'expires_at',
    ];

    /**
     * @param array<string, mixed> $raw
     * @param OpenEnum<PayoutLinkStatus> $status
     * @param OpenEnum<FeeBearer> $fee_bearer
     */
    public function __construct(
        /** Lifecycle of the underlying link (cheque). */
        public readonly OpenEnum $status,
        /** True — the link can be claimed right now. */
        public readonly bool $claimable,
        /** Amount the recipient may claim, decimal string. */
        public readonly string $amount,
        /** Link currency code. */
        public readonly string $currency,
        /** Blockchain network. */
        public readonly string $network,
        /** Network fee, in the link currency. Null while the asset cannot be priced. */
        public readonly ?string $commission,
        /** How much would actually reach the recipient's address. Null while unpriced. */
        public readonly ?string $payer_amount,
        /** Who is asked to bear the network fee. */
        public readonly OpenEnum $fee_bearer,
        /** Pricing mode behind `commission` (`percent`/`fixed`/…). Open vocabulary. */
        public readonly string $fee_type,
        /** Title shown to the recipient. */
        public readonly string $title,
        /** Note shown to the recipient. */
        public readonly string $note,
        /** When the link stops being claimable (RFC 3339). */
        public readonly string $expires_at,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::enum(PayoutLinkStatus::class, $data, 'status'),
            Wire::bool($data, 'claimable'),
            Wire::str($data, 'amount'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'network'),
            Wire::nullableStr($data, 'commission'),
            Wire::nullableStr($data, 'payer_amount'),
            Wire::enum(FeeBearer::class, $data, 'fee_bearer'),
            Wire::str($data, 'fee_type'),
            Wire::str($data, 'title'),
            Wire::str($data, 'note'),
            Wire::str($data, 'expires_at'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
