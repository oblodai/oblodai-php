<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use JsonSerializable;

/** `POST /v1/webhooks`. */
final class WebhookEndpoint implements JsonSerializable
{
    use RedactsSecrets;

    /** @var list<string> */
    public const KEYS = ['endpoint_id', 'url', 'secret'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Endpoint id. */
        public readonly string $endpoint_id,
        /** Registered callback URL. */
        public readonly string $url,
        /**
         * The secret is shown ONCE — save it so you can verify callback signatures. Absent when
         * only the URL was changed.
         */
        public readonly ?string $secret = null,
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
            Wire::nullableStr($data, 'secret'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
