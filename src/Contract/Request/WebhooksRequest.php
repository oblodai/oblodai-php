<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/webhooks`.
 */
final class WebhooksRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * HTTPS callback URL. SSRF check: private and local addresses are rejected.
         * Example: "https://shop.example/oblodai/callback".
         */
        public readonly string $url,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'url' => $this->url,
        ]);
    }
}
