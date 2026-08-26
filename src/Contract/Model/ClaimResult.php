<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\FeeBearer;

/** `POST /v1/claim/{token}` — the payout minted by a claim. */
final class ClaimResult
{
    /** @var list<string> */
    public const KEYS = [
        'payout_id', 'status', 'address', 'amount', 'currency', 'network', 'commission',
        'payer_amount', 'fee_bearer', 'fee_type',
    ];

    /**
     * @param array<string, mixed> $raw
     * @param OpenEnum<FeeBearer> $fee_bearer
     */
    public function __construct(
        /** The payout that pays the recipient (look it up with `payouts->info()`). */
        public readonly string $payout_id,
        /**
         * Status the core reports for the claim. The recorded body carries the LINK's status
         * (`claimed`), not the payout's, so this stays a plain string rather than an enum: check
         * the payout itself with `payouts->info($payout_id)` for its lifecycle state.
         */
        public readonly string $status,
        /** Address the recipient claimed to. */
        public readonly string $address,
        /** Amount paid out, decimal string. */
        public readonly string $amount,
        /** Payout currency code. */
        public readonly string $currency,
        /** Blockchain network. */
        public readonly string $network,
        /** Network fee, in the payout currency. Null while the asset cannot be priced. */
        public readonly ?string $commission,
        /** How much actually reaches the recipient's address. Null while unpriced. */
        public readonly ?string $payer_amount,
        /** Who is asked to bear the network fee. */
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
            Wire::str($data, 'payout_id'),
            Wire::str($data, 'status'),
            Wire::str($data, 'address'),
            Wire::str($data, 'amount'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'network'),
            Wire::nullableStr($data, 'commission'),
            Wire::nullableStr($data, 'payer_amount'),
            Wire::enum(FeeBearer::class, $data, 'fee_bearer'),
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
