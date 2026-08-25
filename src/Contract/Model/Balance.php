<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/balance`. */
final class Balance
{
    /** Wire keys the response carries. @var list<string> */
    public const KEYS = ['balance'];

    /**
     * @param list<BalanceEntry>  $merchant
     * @param array<string, mixed> $raw
     */
    public function __construct(
        /** Balances by legal merchant entity, from the wire's `balance.merchant` array. */
        public readonly array $merchant,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $balance = Wire::obj($data, 'balance');

        return new self(
            array_map(
                static fn (array $entry): BalanceEntry => BalanceEntry::fromArray($entry),
                Wire::rows($balance, 'merchant')
            ),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
