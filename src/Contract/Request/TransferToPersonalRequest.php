<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/transfer/to-personal`.
 */
final class TransferToPersonalRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Transfer amount in currency.
         * Example: "50".
         */
        public readonly string $amount,
        /**
         * Currency code (cryptocurrency).
         * Example: "USDT".
         */
        public readonly string $currency,
        /**
         * Idempotency key: a repeat with the same order_id is a no-op. Always send it, otherwise retrying the request after a network timeout creates a second transfer.
         * Example: "transfer-1".
         */
        public readonly ?string $order_id = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'order_id' => $this->order_id,
        ]);
    }
}
