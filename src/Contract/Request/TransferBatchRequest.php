<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\BatchOnError;

/**
 * Body of `POST /v1/transfer/batch`.
 */
final class TransferBatchRequest implements RequestBody
{
    use NormalizesFields;

    /**
     * @param list<array<string, mixed>>|list<\Oblodai\Contract\Request\RequestBody>|null $transfers
     */
    public function __construct(
        /**
         * What to do when an item fails: continue (default) — process the rest; stop — halt processing after the first error.
         * Example: "continue".
         */
        public readonly string|BatchOnError|null $on_error = null,
        /**
         * Array of 1 to 5000 items — the same fields as POST /v1/transfer/to-user; every item requires order_id (idempotency key) and to_user_id (user UUID).
         * Each item:
         *   - `amount` — Transfer amount in currency.
         *   - `currency` — Currency code (cryptocurrency).
         *   - `order_id` — Idempotency key: a repeat with the same order_id is a no-op; required in a transfer batch.
         *   - `to_user_id` (required) — Platform user id of the recipient (UUID, not username); a username is resolved to an id via the cabinet public profile /public/users/{username}.
         */
        public readonly ?array $transfers = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'on_error' => $this->on_error,
            'transfers' => $this->transfers,
        ]);
    }
}
