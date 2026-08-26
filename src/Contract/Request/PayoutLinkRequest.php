<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\FeeBearer;
use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/payout/link`.
 */
final class PayoutLinkRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Amount in currency, as a string; greater than zero.
         * Example: "25".
         */
        public readonly string $amount,
        /**
         * Payout crypto asset (USDT, BTC, …); fiat is not possible.
         * Example: "USDT".
         */
        public readonly string $currency,
        /**
         * Payout network for the recipient (tron, bitcoin, …).
         * Example: "tron".
         */
        public readonly string|Network $network,
        /**
         * If set, the recipient receives an email with a "Claim funds" button; a delivery failure does not cancel link creation.
         * Example: "user@example.com".
         */
        public readonly ?string $email = null,
        /**
         * Link lifetime in seconds, clamped to 3600–2592000 (one hour to 30 days); without the field or with 0 the link lives 1 hour, not the maximum — set it explicitly.
         * Example: 604800.
         */
        public readonly ?int $expires_in_seconds = null,
        /**
         * Who pays the network fee: "recipient" (default — deducted from the amount, the recipient receives less) or "merchant" (the amount plus the fee is reserved, the recipient receives exactly amount).
         * Example: "merchant".
         */
        public readonly string|FeeBearer|null $fee_bearer = null,
        /**
         * Message to the recipient (shown on the claim page and in the email).
         * Example: "Thank you for taking part".
         */
        public readonly ?string $note = null,
        /**
         * Claim code — a second factor for the link: "auto" — we generate it and return it ONCE in the response, or your own (6–64 visible characters), empty — no code. Pass the code to the recipient over a channel SEPARATE from the link (it is not put into the email); after 10 incorrect attempts the link is locked.
         * Example: "auto".
         */
        public readonly ?string $passcode = null,
        /**
         * Your deduplication key, unique per merchant; the Idempotency-Key header has no effect on this endpoint.
         * Example: "bonus-42".
         */
        public readonly ?string $reference = null,
        /**
         * Title — shown to the recipient on the claim page.
         * Example: "Bonus".
         */
        public readonly ?string $title = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'network' => $this->network,
            'email' => $this->email,
            'expires_in_seconds' => $this->expires_in_seconds,
            'fee_bearer' => $this->fee_bearer,
            'note' => $this->note,
            'passcode' => $this->passcode,
            'reference' => $this->reference,
            'title' => $this->title,
        ]);
    }
}
