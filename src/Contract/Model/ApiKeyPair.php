<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** An API key pair as minted by onboarding. The secret is shown once. */
final class ApiKeyPair
{
    /** @var list<string> */
    public const KEYS = ['public_id', 'secret', 'kind'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Public identifier for this key, safe to expose. */
        public readonly string $public_id,
        /** The private key material. Shown once, immediately after creation. */
        public readonly string $secret,
        /** `api` — the unified key kind current merchants receive. */
        public readonly string $kind,
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
            Wire::str($data, 'kind'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
