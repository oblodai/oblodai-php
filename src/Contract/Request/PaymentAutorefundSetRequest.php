<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payment/autorefund/set`.
 */
final class PaymentAutorefundSetRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Refund the excess on overpayment (paid_over).
         * Example: true.
         */
        public readonly bool $overpay,
        /**
         * Refund the funds on an expired underpayment (wrong_amount).
         * Example: true.
         */
        public readonly bool $underpay,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'overpay' => $this->overpay,
            'underpay' => $this->underpay,
        ]);
    }
}
