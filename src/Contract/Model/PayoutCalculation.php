<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\FeeBearerResult;

/** `/v1/payout/calculate`. Amounts are null when the asset cannot be priced right now. */
final class PayoutCalculation
{
    /** @var list<string> */
    public const KEYS = ['amount', 'currency', 'network', 'commission', 'payer_amount', 'fee_bearer', 'fee_type'];

    /**
     * @param array<string, mixed> $raw
     * @param OpenEnum<FeeBearerResult> $fee_bearer
     */
    public function __construct(
        /** Payout amount in currency. Null when the asset cannot be priced right now. */
        public readonly ?string $amount,
        /** Payout currency code. */
        public readonly string $currency,
        /** Blockchain network. */
        public readonly string $network,
        /** Network fee that would be withheld, in the payout currency. Null — unpriced. */
        public readonly ?string $commission,
        /** How much would actually reach the recipient's address. Null — unpriced. */
        public readonly ?string $payer_amount,
        /** Who would pay the network fee: gateway, merchant, or recipient. */
        public readonly OpenEnum $fee_bearer,
        /** Pricing mode behind `commission` (`percent`/`fixed`/…). Open vocabulary. */
        public readonly string $fee_type,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::nullableStr($data, 'amount'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'network'),
            Wire::nullableStr($data, 'commission'),
            Wire::nullableStr($data, 'payer_amount'),
            Wire::enum(FeeBearerResult::class, $data, 'fee_bearer'),
            Wire::str($data, 'fee_type'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
