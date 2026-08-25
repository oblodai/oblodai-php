<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/payment/fee-config/get` and `/set`. */
final class PaymentFeeConfig
{
    /** @var list<string> */
    public const KEYS = ['payer_pays_percent', 'enabled'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Share of the network fee passed on to the payer, in percent (0-100). */
        public readonly int $payer_pays_percent,
        /** `get` only: true — passing the fee to the payer is enabled. */
        public readonly ?bool $enabled = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::int($data, 'payer_pays_percent'),
            Wire::nullableBool($data, 'enabled'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
