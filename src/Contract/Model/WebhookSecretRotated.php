<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use JsonSerializable;

/** `POST /v1/webhooks/rotate-secret`. */
final class WebhookSecretRotated implements JsonSerializable
{
    use RedactsSecrets;

    /** @var list<string> */
    public const KEYS = ['endpoint_id', 'url', 'secret', 'previous_secret_valid_until'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Endpoint id. */
        public readonly string $endpoint_id,
        /** Registered callback URL. */
        public readonly string $url,
        /** New signing secret — shown only here. */
        public readonly string $secret,
        /** Until this moment deliveries are additionally signed with the old secret (`X-Webhook-Signature-Prev`). */
        public readonly string $previous_secret_valid_until,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'endpoint_id'),
            Wire::str($data, 'url'),
            Wire::str($data, 'secret'),
            Wire::str($data, 'previous_secret_valid_until'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
