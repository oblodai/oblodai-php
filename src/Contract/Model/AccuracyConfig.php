<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/payment/accuracy/*`. */
final class AccuracyConfig
{
    /** @var list<string> */
    public const KEYS = ['enabled', 'accuracy_percent'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** True — underpayments within `accuracy_percent` are still accepted as paid in full. */
        public readonly bool $enabled,
        /** Tolerance, percent of the amount due. */
        public readonly int $accuracy_percent,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::bool($data, 'enabled'),
            Wire::int($data, 'accuracy_percent'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
