<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 2cc44c16f516).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/sandbox/webhooks/replay`.
 */
final class SandboxWebhooksReplayRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /** Delivery id from GET /v1/sandbox/webhooks. */
        public readonly string $delivery_id,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'delivery_id' => $this->delivery_id,
        ]);
    }
}
