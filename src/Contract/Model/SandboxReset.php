<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `POST /v1/sandbox/reset`. */
final class SandboxReset
{
    /** @var list<string> */
    public const KEYS = ['invoices_cancelled', 'balances_zeroed'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Number of sandbox invoices cancelled. */
        public readonly int $invoices_cancelled,
        /** Number of sandbox balances zeroed. */
        public readonly int $balances_zeroed,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::int($data, 'invoices_cancelled'),
            Wire::int($data, 'balances_zeroed'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
