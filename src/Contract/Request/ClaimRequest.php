<?php

declare(strict_types=1);

// GENERATED FILE — do not edit. Source: contract/contract.json (core 7ec04293c426).
// Regenerate with: composer codegen

namespace Oblodai\Contract\Request;

/**
 * Body of `POST /v1/claim/{token}`.
 */
final class ClaimRequest implements RequestBody
{
    use NormalizesFields;

    public function __construct(
        /** Recipient address in the payout network. */
        public readonly string $address,
        /** Memo/tag — only for networks where it is required. */
        public readonly ?string $memo = null,
        /** Claim code — if the sender set one on the link. After 10 incorrect attempts the link is locked. */
        public readonly ?string $passcode = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::normalize([
            'address' => $this->address,
            'memo' => $this->memo,
            'passcode' => $this->passcode,
        ]);
    }
}
