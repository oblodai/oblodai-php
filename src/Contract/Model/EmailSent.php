<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `POST /v1/payment/send-email`. */
final class EmailSent
{
    /** @var list<string> */
    public const KEYS = ['ok', 'email', 'uuid'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Whether the e-mail was accepted for sending. */
        public readonly bool $ok,
        /** Address the confirmation was sent to. */
        public readonly string $email,
        /** The invoice this e-mail is for. */
        public readonly string $uuid,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::bool($data, 'ok'),
            Wire::str($data, 'email'),
            Wire::str($data, 'uuid'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
