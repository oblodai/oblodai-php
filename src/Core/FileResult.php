<?php

declare(strict_types=1);

namespace Oblodai\Core;

use Oblodai\Exception\ConfigException;

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

    /**
     * Write the document to a path and return how many bytes landed there.
     *
     * A failed write throws: returning 0 would look exactly like an empty document and let a
     * caller record "statement saved" for a file that does not exist.
     */
    public function saveTo(string $path): int
    {
        // The warning PHP would emit says the same thing as the exception and lands in a different
        // channel; the exception is the one a caller can act on.
        $written = @file_put_contents($path, $this->bytes);
        if ($written === false || $written !== strlen($this->bytes)) {
            $reason = error_get_last()['message'] ?? 'write failed';

            throw new ConfigException(
                ConfigException::BAD_CONFIG,
                sprintf('could not write %d bytes to %s: %s', strlen($this->bytes), $path, $reason),
                'path'
            );
        }

        return $written;
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
