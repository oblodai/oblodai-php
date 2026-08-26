<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use JsonSerializable;

/** `POST /v1/merchants` — a freshly provisioned merchant and its API key. */
final class MerchantOnboarded implements JsonSerializable
{
    use RedactsSecrets;

    /** @var list<string> */
    public const KEYS = ['merchant_id', 'project_id', 'api_key'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** The merchant's id. */
        public readonly string $merchant_id,
        /** The default project's id. */
        public readonly string $project_id,
        /** The one API key that signs every call this merchant makes. */
        public readonly ApiKeyPair $api_key,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'merchant_id'),
            Wire::str($data, 'project_id'),
            ApiKeyPair::fromArray(Wire::obj($data, 'api_key')),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
