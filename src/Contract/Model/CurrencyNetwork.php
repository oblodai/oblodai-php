<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** One network a currency settles on, nested in `CurrencyInfo.networks` (`GET /v1/currencies`). */
final class CurrencyNetwork
{
    /** @var list<string> */
    public const KEYS = [
        'network', 'kind', 'min_confirmations', 'available', 'deposit_available',
        'payout_available', 'default_offer',
    ];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Blockchain network. */
        public readonly string $network,
        /** `native` or `token`. */
        public readonly string $kind,
        /** How many confirmations a deposit needs before crediting. */
        public readonly int $min_confirmations,
        /** Deposits and payouts both possible right now. */
        public readonly bool $available,
        /** Deposits are currently possible. */
        public readonly bool $deposit_available,
        /** Payouts are currently possible. */
        public readonly bool $payout_available,
        /** The network offered first on the pay page. */
        public readonly bool $default_offer,
        /** Token contract address, for tokens. */
        public readonly ?string $contract = null,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'network'),
            Wire::str($data, 'kind'),
            Wire::int($data, 'min_confirmations'),
            Wire::bool($data, 'available'),
            Wire::bool($data, 'deposit_available'),
            Wire::bool($data, 'payout_available'),
            Wire::bool($data, 'default_offer'),
            Wire::nullableStr($data, 'contract'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
