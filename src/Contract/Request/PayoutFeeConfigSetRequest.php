<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payout/fee-config/set`.
 */
final class PayoutFeeConfigSetRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * true — the recipient pays the network fee (receives less); false — the merchant bears the fee.
         * Example: true.
         */
        public readonly bool $fee_on_recipient,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'fee_on_recipient' => $this->fee_on_recipient,
        ]);
    }
}
