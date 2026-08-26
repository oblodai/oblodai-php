<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/wallet/block`.
 */
final class WalletBlockRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /**
         * Static wallet address.
         * Example: "TXk9...c3Fd".
         */
        public readonly string $address,
        /** true — block (the default when the field is omitted); false — unblock. */
        public readonly ?bool $is_force_block = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'address' => $this->address,
            'is_force_block' => $this->is_force_block,
        ]);
    }
}
