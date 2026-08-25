<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/vrcs` — volatility risk control (auto-convert volatile deposits to USDT). */
final class VrcsStatus
{
    /** @var list<string> */
    public const KEYS = ['enabled'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** True — volatile deposits are auto-converted to USDT. */
        public readonly bool $enabled,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::bool($data, 'enabled'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
