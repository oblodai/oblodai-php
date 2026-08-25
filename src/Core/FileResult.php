<?php

declare(strict_types=1);

namespace Oblodai\Core;

/** A binary response body (PDF/CSV documents) with the metadata needed to save or serve it. */
final class FileResult
{
    public function __construct(
        /** The document bytes. */
        public readonly string $bytes,
        /** `application/pdf`, `text/csv`, … */
        public readonly string $contentType,
        /** Name the core suggested in `Content-Disposition`, when it did. */
        public readonly ?string $filename = null,
    ) {
    }

    /** Bytes written to a path; returns the number of bytes written. */
    public function saveTo(string $path): int
    {
        $written = file_put_contents($path, $this->bytes);

        return $written === false ? 0 : $written;
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }

    /** Parse a `Content-Disposition` header into a file name. */
    public static function filenameFrom(?string $disposition): ?string
    {
        if ($disposition === null || $disposition === '') {
            return null;
        }
        if (preg_match("/filename\*=UTF-8''([^;]+)/i", $disposition, $m) === 1) {
            return rawurldecode($m[1]);
        }
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
