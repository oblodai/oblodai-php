<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

/** The finished file nested in `DocumentJob.file`, once a job is done. */
final class DocumentJobFile
{
    /** @var list<string> */
    public const KEYS = ['download_url', 'expires_at', 'rows', 'size_bytes'];

    /** @param array<string, mixed> $raw */
    public function __construct(
        /** Signed link to the finished file (or use `documents.jobFile`). */
        public readonly string $download_url,
        /** When `download_url` stops working (RFC 3339). */
        public readonly string $expires_at,
        /** Number of data rows in the file. */
        public readonly int $rows,
        /** File size, in bytes. */
        public readonly int $size_bytes,
        /** The wire body as received, including any field newer than this SDK. */
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Wire::str($data, 'download_url'),
            Wire::str($data, 'expires_at'),
            Wire::int($data, 'rows'),
            Wire::int($data, 'size_bytes'),
            $data,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->raw;
    }
}
