<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** Static (permanent) deposit wallet — `/v1/wallet`. */
final class Wallet
{
    /** Wire keys every rendering of a wallet carries. @var list<string> */
    public const KEYS = [
        'uuid', 'address', 'network', 'currency', 'order_id', 'url', 'document_url', 'blocked',
    ];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Static wallet id. */
        public readonly string $uuid,
        /** Permanent address for top-ups. On XRP it is the classic r-address of the SHARED wallet; a top-up must carry `destination_tag`. */
        public readonly string $address,
        /** Blockchain network. */
        public readonly string $network,
        /** Top-up currency. */
        public readonly string $currency,
        /** Your customer identifier the address is pinned to (part of the currency+network+order_id idempotency triple). */
        public readonly string $order_id,
        /** Reserved (usually empty). */
        public readonly string $url,
        /** Signed link to the PDF document for this wallet; empty when documents are disabled. */
        public readonly string $document_url,
        /** True once `wallets.block()` was called: new deposits are quarantined instead of credited. */
        public readonly bool $blocked,
        /** XRP only: numeric destination tag of this wallet — the customer must include it in every transfer. */
        public readonly ?string $destination_tag = null,
        /** XLM only: numeric memo (type ID) of this wallet — the customer must include it in every transfer. */
        public readonly ?string $memo = null,
        /** XRP only: address and tag in one string (X-address, XLS-5). */
        public readonly ?string $address_xaddress = null,
        /** XLM only: address and memo in one string (muxed M…, SEP-23). */
        public readonly ?string $address_muxed = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'uuid'),
            Wire::str($data, 'address'),
            Wire::str($data, 'network'),
            Wire::str($data, 'currency'),
            Wire::str($data, 'order_id'),
            Wire::str($data, 'url'),
            Wire::str($data, 'document_url'),
            Wire::bool($data, 'blocked'),
            Wire::nullableStr($data, 'destination_tag'),
            Wire::nullableStr($data, 'memo'),
            Wire::nullableStr($data, 'address_xaddress'),
            Wire::nullableStr($data, 'address_muxed'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
