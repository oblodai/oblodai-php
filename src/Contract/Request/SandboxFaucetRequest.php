<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/sandbox/faucet`.
 */
final class SandboxFaucetRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Amount of test money, as a string; capped at 1000000 per call.
         * Example: "1000".
         */
        public readonly string $amount,
        /**
         * Top-up asset (USDT, BTC, …).
         * Example: "USDT".
         */
        public readonly string $asset,
        /** Safe-retry key; empty — every call creates a new top-up. */
        public readonly ?string $idempotency_key = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'amount' => $this->amount,
            'asset' => $this->asset,
            'idempotency_key' => $this->idempotency_key,
        ]);
    }
}
