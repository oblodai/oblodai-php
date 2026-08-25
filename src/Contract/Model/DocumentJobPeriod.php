<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** The date range nested in `DocumentJob.period`. */
final class DocumentJobPeriod
{
    /** @var list<string> */
    public const KEYS = ['from', 'to'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Start of the reporting period. */
        public readonly string $from,
        /** End of the reporting period. */
        public readonly string $to,
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
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
