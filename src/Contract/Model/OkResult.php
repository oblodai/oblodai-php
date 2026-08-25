<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** Bare acknowledgement used by simple actions (for example `POST /v1/payment/resend`). */
final class OkResult
{
    /** @var list<string> */
    public const KEYS = ['ok'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly bool $ok,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::bool($data, 'ok'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
