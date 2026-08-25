<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use Oblodai\Contract\Enum\FeeBearer;
use Oblodai\Contract\Enum\PayoutLinkStatus;

/** Payout link (cheque) as `/v1/payout/link`, `/info`, `/list`, `/cancel` and batch elements render it. */
final class PayoutLink
{
    /** Wire keys every rendering of a payout link carries. @var list<string> */
    public const KEYS = [
        'link_id', 'status', 'amount', 'currency', 'network', 'commission', 'payer_amount', 'fee_bearer',
        'fee_type', 'reference', 'title', 'note', 'passcode_protected', 'expires_at', 'created_at',
    ];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Payout link id. */
        public readonly string $link_id,
        /** Lifecycle of the link (cheque). */
        public readonly PayoutLinkStatus $status,
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
        public readonly FeeBearer $fee_bearer,
        /** Pricing mode behind `commission` (`percent`/`fixed`/…). Open vocabulary. */
        public readonly string $fee_type,
        /** Your reference for this link. */
        public readonly string $reference,
        /** Title shown to the recipient. */
        public readonly string $title,
        /** Note shown to the recipient. */
        public readonly string $note,
        /** True — a passcode is required to claim. */
        public readonly bool $passcode_protected,
        /** When the link stops being claimable (RFC 3339). */
        public readonly string $expires_at,
        /** Creation time (RFC 3339). */
        public readonly string $created_at,
        /** Present on create and batch-create only: the secret the recipient claims with. */
        public readonly ?string $claim_token = null,
        /** Present on create and batch-create only: the URL the recipient opens to claim. */
        public readonly ?string $claim_url = null,
        /** Set when the link was created as part of a batch. */
        public readonly ?string $batch_id = null,
        /** Set once claimed: the payout that paid the recipient. */
        public readonly ?string $payout_id = null,
        /** Set once claimed: the address the recipient claimed to. */
        public readonly ?string $claim_address = null,
        /** Recipient e-mail, if one was set at creation. */
        public readonly ?string $email = null,
        /** The generated passcode, shown once on create when `passcode: "auto"` was requested. */
        public readonly ?string $passcode = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'link_id'),
            Wire::enum(PayoutLinkStatus::class, $data, 'status'),
            Wire::str($data, 'amount'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'network'),
            Wire::nullableStr($data, 'commission'),
            Wire::nullableStr($data, 'payer_amount'),
            Wire::enum(FeeBearer::class, $data, 'fee_bearer'),
            Wire::str($data, 'fee_type'),
            Wire::str($data, 'reference'),
            Wire::str($data, 'title'),
            Wire::str($data, 'note'),
            Wire::bool($data, 'passcode_protected'),
            Wire::str($data, 'expires_at'),
            Wire::str($data, 'created_at'),
            Wire::nullableStr($data, 'claim_token'),
            Wire::nullableStr($data, 'claim_url'),
            Wire::nullableStr($data, 'batch_id'),
            Wire::nullableStr($data, 'payout_id'),
            Wire::nullableStr($data, 'claim_address'),
            Wire::nullableStr($data, 'email'),
            Wire::nullableStr($data, 'passcode'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
