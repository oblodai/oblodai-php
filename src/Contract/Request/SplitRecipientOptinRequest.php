<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/split/recipient/optin`.
 */
final class SplitRecipientOptinRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Allow other merchants to route split shares to your balance. true — enable receiving, false — disable (new rules targeting you stop being created; existing ones keep executing).
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
