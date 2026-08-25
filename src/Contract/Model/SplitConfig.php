<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/split/config/*`. */
final class SplitConfig
{
    /** @var list<string> */
    public const KEYS = ['refund_hold_seconds'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** How long a split share is held before release, to allow a refund to claw it back. */
        public readonly int $refund_hold_seconds,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::int($data, 'refund_hold_seconds'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
