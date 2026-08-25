<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/wallet/qr` — the address QR as a data URI. */
final class WalletQr
{
    /** @var list<string> */
    public const KEYS = ['image'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** The address QR code as a PNG `data:` URI — usable directly in `<img src>`. */
        public readonly string $image,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'image'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
