<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/payout/fee-config/get` and `/set`. */
final class PayoutFeeConfig
{
    /** @var list<string> */
    public const KEYS = ['fee_on_recipient', 'configured'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** True — the network fee is withheld from the recipient rather than absorbed. */
        public readonly bool $fee_on_recipient,
        /** `get` only: true — a value was explicitly configured (as opposed to the default). */
        public readonly ?bool $configured = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::bool($data, 'fee_on_recipient'),
            Wire::nullableBool($data, 'configured'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
