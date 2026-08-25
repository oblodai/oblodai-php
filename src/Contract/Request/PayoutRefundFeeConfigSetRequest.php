<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payout/refund-fee-config/set`.
 */
final class PayoutRefundFeeConfigSetRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * true — the customer receives net (the customer pays the fee); false — the merchant pays the fee and the customer receives gross.
         * Example: true.
         */
        public readonly bool $fee_on_customer,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'fee_on_customer' => $this->fee_on_customer,
        ]);
    }
}
