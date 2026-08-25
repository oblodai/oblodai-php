<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7b8eb828b9ec).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/wallet/qr`.
 */
final class WalletQrRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /** Arbitrary address to render into a QR code (PNG as a data: URI). */
        public readonly string $address,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'address' => $this->address,
        ]);
    }
}
