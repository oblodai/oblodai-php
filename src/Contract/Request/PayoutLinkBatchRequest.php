<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payout/link/batch`.
 */
final class PayoutLinkBatchRequest implements RequestBody
{
    use NormalizesFields;

    /**
     * @param list<array<string, mixed>>|list<\Oblodai\Contract\Request\RequestBody> $items
     */
    public function __construct(
        /**
         * Up to 500 links per call; each one succeeds or fails independently, the response is aligned with the request indexes.
         * Each item:
         *   - `amount` (required) — Amount in currency, as a string; greater than zero.
         *   - `currency` (required) — Payout crypto asset (USDT, BTC, …); fiat is not possible.
         *   - `email` — If set, the recipient receives an email with a "Claim funds" button; a delivery failure does not cancel link creation.
         *   - `expires_in_seconds` — Link lifetime in seconds, clamped to 3600–2592000 (one hour to 30 days); without the field or with 0 the link lives 1 hour, not the maximum — set it explicitly.
         *   - `fee_bearer` — Who pays the network fee: "recipient" (default — deducted from the amount, the recipient receives less) or "merchant" (the amount plus the fee is reserved, the recipient receives exactly amount).
         *   - `network` (required) — Payout network for the recipient (tron, bitcoin, …).
         *   - `note` — Message to the recipient (shown on the claim page and in the email).
         *   - `passcode` — Claim code — a second factor for the link: "auto" — we generate it and return it ONCE in the response, or your own (6–64 visible characters), empty — no code. Pass the code to the recipient over a channel SEPARATE from the link (it is not put into the email); after 10 incorrect attempts the link is locked.
         *   - `reference` — Your deduplication key, unique per merchant; the Idempotency-Key header has no effect on this endpoint.
         *   - `title` — Title — shown to the recipient on the claim page.
         */
        public readonly array $items,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'items' => $this->items,
        ]);
    }
}
