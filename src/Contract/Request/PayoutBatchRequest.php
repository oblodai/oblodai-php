<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

use Oblodai\Contract\Enum\BatchOnError;

/**
 * Body of `POST /v1/payout/batch`.
 */
final class PayoutBatchRequest implements RequestBody
{
    use NormalizesFields;

    /**
     * @param list<array<string, mixed>>|list<\Oblodai\Contract\Request\RequestBody> $payouts
     */
    public function __construct(
        /**
         * Array of 1 to 5000 items — the same fields as POST /v1/payout; order_id is required on every item and serves as the idempotency key: a repeat returns the already created payout.
         * Each item:
         *   - `address` (required) — Recipient address.
         *   - `amount` (required) — Payout amount, in currency.
         *   - `currency` (required) — Currency code (for example USDT).
         *   - `from_currency` — Fund the payout by converting the balance. USDT → currency only.
         *   - `is_subtract` — Who pays the network fee: true — amount+fee is debited from the balance and the recipient receives amount; false — the recipient receives amount-fee; not provided — the project fee-config.
         *   - `memo` — Destination tag/memo (TON Jetton). Maximum 120 characters.
         *   - `network` — Network (tron, ethereum, …). Required for coins with several networks.
         *   - `order_id` (required) — Your payout number; idempotency key.
         *   - `source` — Origin label: api (default) or manual.
         *   - `url_callback` — Custom webhook URL for this payout (passes the SSRF check). Requires a registered endpoint (POST /v1/webhooks): delivery is signed with its secret.
         */
        public readonly array $payouts,
        /**
         * What to do when an item fails: continue (default) — process the rest; stop — halt processing after the first error.
         * Example: "continue".
         */
        public readonly string|BatchOnError|null $on_error = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'payouts' => $this->payouts,
            'on_error' => $this->on_error,
        ]);
    }
}
