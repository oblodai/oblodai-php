<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `POST /v1/sandbox/deposit`. */
final class SandboxDeposit
{
    /** @var list<string> */
    public const KEYS = ['invoice_id', 'amount', 'confirmations', 'txid'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** The invoice credited. */
        public readonly string $invoice_id,
        /** Amount deposited, decimal string. */
        public readonly string $amount,
        /** Confirmations granted to the synthetic deposit. */
        public readonly int $confirmations,
        /** Synthetic transaction hash (`sandbox:…`-prefixed). */
        public readonly string $txid,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'invoice_id'),
            Wire::str($data, 'amount'),
            Wire::int($data, 'confirmations'),
            Wire::str($data, 'txid'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
