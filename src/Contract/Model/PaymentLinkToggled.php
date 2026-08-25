<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `POST /v1/payment/link/toggle` acknowledgement. */
final class PaymentLinkToggled
{
    /** @var list<string> */
    public const KEYS = ['link_id', 'active'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Link id. */
        public readonly string $link_id,
        /** True — the link now accepts new payments. */
        public readonly bool $active,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'link_id'),
            Wire::bool($data, 'active'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
