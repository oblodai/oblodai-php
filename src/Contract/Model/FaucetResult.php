<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `POST /v1/sandbox/faucet`. */
final class FaucetResult
{
    /** @var list<string> */
    public const KEYS = ['asset', 'amount', 'journal_id'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Asset credited. */
        public readonly string $asset,
        /** Amount credited, decimal string. */
        public readonly string $amount,
        /** Ledger entry id for this credit. */
        public readonly string $journal_id,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'asset'),
            Wire::str($data, 'amount'),
            Wire::str($data, 'journal_id'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
