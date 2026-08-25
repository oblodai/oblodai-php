<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/split/recipient/optin*` — a partner's opt-in into being a split recipient. */
final class SplitOptIn
{
    /** @var list<string> */
    public const KEYS = ['enabled'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** True — this account accepts being named in other merchants' split rules. */
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
