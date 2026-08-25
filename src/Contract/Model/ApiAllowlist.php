<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/api-allowlist/*` — entries are CIDRs. */
final class ApiAllowlist
{
    /** @var list<string> */
    public const KEYS = ['enabled', 'items'];

    /**
     * @param list<string>          $items
     * @param array<string, mixed>  $raw
     */
    public function __construct(
        /** True — only requests from `items` are accepted. */
        public readonly bool $enabled,
        /** Allowed CIDRs. */
        public readonly array $items,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::bool($data, 'enabled'),
            Wire::strings($data, 'items'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
