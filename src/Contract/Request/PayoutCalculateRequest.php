<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\Network;

/**
 * Body of `POST /v1/payout/calculate`.
 */
final class PayoutCalculateRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Payout amount as a decimal string.
         * Example: "10".
         */
        public readonly string $amount,
        /**
         * Payout asset (USDT, BTC, …).
         * Example: "USDT".
         */
        public readonly string $currency,
        /** true — the fee is debited from the balance on top of the amount (the recipient gets exactly amount); false — the fee is taken out of the payout. */
        public readonly ?bool $is_subtract = null,
        /**
         * Payout network; required when the asset lives on several networks.
         * Example: "tron".
         */
        public readonly string|Network|null $network = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'is_subtract' => $this->is_subtract,
            'network' => $this->network,
        ]);
    }
}
