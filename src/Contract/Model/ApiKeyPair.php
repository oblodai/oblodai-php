<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use JsonSerializable;

/** The merchant's API key as minted by onboarding. The secret is shown once. */
final class ApiKeyPair implements JsonSerializable
{
    use RedactsSecrets;

    /** @var list<string> */
    public const KEYS = ['public_id', 'secret'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Public identifier for this key, safe to expose. Travels in `X-Public-Id`. */
        public readonly string $public_id,
        /** The private key material. Shown once, immediately after creation. */
        public readonly string $secret,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'public_id'),
            Wire::str($data, 'secret'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
