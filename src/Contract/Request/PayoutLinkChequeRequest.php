<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payout/link/cheque`.
 */
final class PayoutLinkChequeRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Claim secret from the payout link creation response. Stored only as a hash and never reissued — the cheque can be printed only while you still hold the token.
         * Example: "nUqx1yG3...".
         */
        public readonly string $claim_token,
        /**
         * Document language — one of the 41 supported codes (en by default); the full list is in the document.unknown_lang error.
         * Example: "ru".
         */
        public readonly ?string $lang = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'claim_token' => $this->claim_token,
            'lang' => $this->lang,
        ]);
    }
}
