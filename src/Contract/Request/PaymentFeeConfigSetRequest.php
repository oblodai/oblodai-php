<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/payment/fee-config/set`.
 */
final class PaymentFeeConfigSetRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Share of OUR commission paid by the buyer: 0 — the merchant pays (current behaviour), 100 — the buyer pays and the invoice is issued with a markup. Applies to invoices created AFTER the change.
         * Example: 100.
         */
        public readonly int $payer_pays_percent,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'payer_pays_percent' => $this->payer_pays_percent,
        ]);
    }
}
