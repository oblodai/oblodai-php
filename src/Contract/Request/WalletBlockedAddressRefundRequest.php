<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core bfca971cce71).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/wallet/blocked-address-refund`.
 */
final class WalletBlockedAddressRefundRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /** Refund destination address. */
        public readonly string $address,
        /** Static wallet id (from the /v1/wallet response). */
        public readonly string $uuid,
        /** Destination tag/memo (XRP destination tag, XLM memo id, TON comment). Required for a classic address on a tag/memo network if the tag is not embedded in the X-/M-address. */
        public readonly ?string $memo = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'address' => $this->address,
            'uuid' => $this->uuid,
            'memo' => $this->memo,
        ]);
    }
}
