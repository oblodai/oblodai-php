<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\FeeBearerResult;

/** `/v1/payout/validate` — the dry run; errors are the same the create call would raise. */
final class PayoutValidation
{
    /** @var list<string> */
    public const KEYS = [
        'valid', 'amount', 'currency', 'network', 'commission', 'payer_amount', 'fee_bearer', 'maturity_note',
    ];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** True — the payout would go through as requested. */
        public readonly bool $valid,
        /** Payout amount in currency, that would be debited from your balance. */
        public readonly string $amount,
        /** Payout currency code. */
        public readonly string $currency,
        /** Blockchain network. */
        public readonly string $network,
        /** Network fee that would be withheld, in the payout currency. */
        public readonly string $commission,
        /** How much would actually reach the recipient's address. */
        public readonly string $payer_amount,
        /** Who would pay the network fee: gateway, merchant, or recipient. */
        public readonly FeeBearerResult $fee_bearer,
        /** Non-empty when part of the balance is still maturing (reorg window). */
        public readonly string $maturity_note,
        /** Which balance would fund it (`business`/`personal`), when reported. */
        public readonly ?string $funded_by = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::bool($data, 'valid'),
            Wire::str($data, 'amount'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'network'),
            Wire::str($data, 'commission'),
            Wire::str($data, 'payer_amount'),
            Wire::enum(FeeBearerResult::class, $data, 'fee_bearer'),
            Wire::str($data, 'maturity_note'),
            Wire::nullableStr($data, 'funded_by'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
