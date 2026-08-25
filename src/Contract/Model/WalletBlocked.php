<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/wallet/block`. */
final class WalletBlocked
{
    /** @var list<string> */
    public const KEYS = ['uuid', 'address', 'blocked'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Static wallet id. */
        public readonly string $uuid,
        /** The wallet's address. */
        public readonly string $address,
        /** True — the wallet is now blocked for new top-ups. */
        public readonly bool $blocked,
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
            Wire::bool($data, 'blocked'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
