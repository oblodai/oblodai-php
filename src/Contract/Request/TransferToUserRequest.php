<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/transfer/to-user`.
 */
final class TransferToUserRequest implements RequestBody
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
        /** Platform user id of the recipient (UUID, not username); a username is resolved to an id via the cabinet public profile /public/users/{username}. */
        public readonly string $to_user_id,
        /** Idempotency key: a repeat with the same order_id is a no-op; required in a transfer batch. */
        public readonly ?string $order_id = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'to_user_id' => $this->to_user_id,
            'order_id' => $this->order_id,
        ]);
    }
}
