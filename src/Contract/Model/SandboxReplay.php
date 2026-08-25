<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `POST /v1/sandbox/webhooks/replay`. */
final class SandboxReplay
{
    /** @var list<string> */
    public const KEYS = ['ok', 'delivery_id'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Whether the replay was accepted. */
        public readonly bool $ok,
        /** The delivery id replayed. */
        public readonly string $delivery_id,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::bool($data, 'ok'),
            Wire::str($data, 'delivery_id'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
