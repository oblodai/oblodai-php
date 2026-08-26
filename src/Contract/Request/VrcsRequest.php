<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/vrcs`.
 */
final class VrcsRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * true — enable auto-conversion of volatile deposits to USDT, false — disable; omit to read the current state.
         * Example: true.
         */
        public readonly ?bool $enabled = null,
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
