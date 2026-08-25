<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** `/v1/exchange-rate/list` item: 1 `from` = `course` `to`. */
final class ExchangeRate
{
    /** @var list<string> */
    public const KEYS = ['from', 'to', 'course'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Source currency. */
        public readonly string $from,
        /** Target currency. */
        public readonly string $to,
        /** How much of `to` one unit of `from` buys. Decimal string. */
        public readonly string $course,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'from'),
            Wire::str($data, 'to'),
            Wire::str($data, 'course'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
