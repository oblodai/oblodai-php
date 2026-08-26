<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/api-allowlist/enable`.
 */
final class ApiAllowlistEnableRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * true — accept API calls only from listed addresses; false — the list is kept but not enforced.
         * Example: true.
         */
        public readonly bool $enabled,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'enabled' => $this->enabled,
        ]);
    }
}
