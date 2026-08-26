<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/merchants`.
 */
final class MerchantsRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Owner email; must be unique across merchants.
         * Example: "owner@shop.example".
         */
        public readonly string $email,
        /**
         * Display name of the merchant.
         * Example: "Acme".
         */
        public readonly ?string $name = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'email' => $this->email,
            'name' => $this->name,
        ]);
    }
}
