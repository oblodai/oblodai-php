<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/**
 * `POST /v1/payment/qr` and `GET /v1/pay/{id}/qr`. All fields are empty while the invoice has no
 * real address: sandbox invoices (synthetic `sandbox:` address) and `select` invoices awaiting a
 * network. Note: `POST /v1/wallet/qr` returns a bare `{ image }` object, not this shape.
 */
final class QrCode
{
    /** @var list<string> */
    public const KEYS = ['image', 'payload', 'is_uri', 'address'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** `data:image/png;base64,…` */
        public readonly string $image,
        /** What the QR encodes: a payment URI when `is_uri`, else the bare address. */
        public readonly string $payload,
        public readonly bool $is_uri,
        public readonly string $address,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'image'),
            Wire::str($data, 'payload'),
            Wire::bool($data, 'is_uri'),
            Wire::str($data, 'address'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
