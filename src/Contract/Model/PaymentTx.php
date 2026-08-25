<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** One on-chain deposit attributed to an invoice (`tx_list` of a payment). */
final class PaymentTx
{
    /** @var list<string> */
    public const KEYS = ['txid', 'amount', 'network', 'height', 'created_at'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Transaction hash. */
        public readonly string $txid,
        /** Transfer amount in the payment currency. Decimal string. */
        public readonly string $amount,
        /**
         * Network the transfer arrived on. On EVM it may differ from the invoice network: a deposit
         * is credited on another chain with the same address too.
         */
        public readonly string $network,
        /** Height of the block the transfer was confirmed in. */
        public readonly int $height,
        /** When the transfer was credited (RFC 3339). */
        public readonly string $created_at,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'txid'),
            Wire::str($data, 'amount'),
            Wire::str($data, 'network'),
            Wire::int($data, 'height'),
            Wire::str($data, 'created_at'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
