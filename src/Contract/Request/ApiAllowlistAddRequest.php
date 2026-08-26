<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/api-allowlist/add`.
 */
final class ApiAllowlistAddRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * IP or subnet in CIDR notation (203.0.113.7 or 203.0.113.0/24).
         * Example: "203.0.113.0/24".
         */
        public readonly string $cidr,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'cidr' => $this->cidr,
        ]);
    }
}
