<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/sandbox/deposit`.
 */
final class SandboxDepositRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /** UUID of the test invoice being "paid". */
        public readonly string $invoice_id,
        /**
         * Amount in the invoice currency; empty — pay exactly what is due, anything else is a way to produce an under/overpayment.
         * Example: "10".
         */
        public readonly ?string $amount = null,
        /**
         * How many confirmations the deposit arrived with; 0 — fully confirmed; fewer than required — a way to test the pending→confirmed transition (repeat the same txid with a higher number).
         * Example: 0.
         */
        public readonly ?int $confirmations = null,
        /** Repeating the same txid tests your idempotency; empty — a new txid. */
        public readonly ?string $txid = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'invoice_id' => $this->invoice_id,
            'amount' => $this->amount,
            'confirmations' => $this->confirmations,
            'txid' => $this->txid,
        ]);
    }
}
