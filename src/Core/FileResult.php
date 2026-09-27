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
     * Write the document to a path and return how many bytes landed there. The file is created with
     * mode 0600 (statements and receipts are private), and an existing file is never replaced
     * unless `$overwrite` is true (`sdk.file_exists`).
     *
     * A failed write throws: returning 0 would look exactly like an empty document and let a
     * caller record "statement saved" for a file that does not exist.
     */
    public function saveTo(string $path, bool $overwrite = false): int
    {
        // 'x' fails when the path exists (atomically, no check-then-write race); 'c' opens or creates
        // without truncating, so the mode can be tightened before a byte is written.
        $handle = @fopen($path, $overwrite ? 'cb' : 'xb');
        if ($handle === false) {
            if (!$overwrite && file_exists($path)) {
                throw new ConfigException(
                    ConfigException::FILE_EXISTS,
                    sprintf('%s already exists; pass overwrite: true to replace it', $path),
                    'path'
                );
            }

            throw self::writeFailed($path, strlen($this->bytes));
        }

        try {
            @chmod($path, 0o600);
            if ($overwrite && !ftruncate($handle, 0)) {
                throw self::writeFailed($path, strlen($this->bytes));
            }
            $written = @fwrite($handle, $this->bytes);
            if ($written === false || $written !== strlen($this->bytes) || !fflush($handle)) {
                throw self::writeFailed($path, strlen($this->bytes));
            }
        } finally {
            fclose($handle);
        }

        return $written;
    }

    private static function writeFailed(string $path, int $size): ConfigException
    {
        $reason = error_get_last()['message'] ?? 'write failed';

        return new ConfigException(
            ConfigException::BAD_CONFIG,
            sprintf('could not write %d bytes to %s: %s', $size, $path, $reason),
            'path'
        );
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }

    /**
     * Parse a `Content-Disposition` header into a safe base name: the header comes from the
     * network, and a caller who saves under this name must not be steered into another directory
     * (`../../.bashrc`, `C:\\x`) or handed control characters. Null when nothing usable is left.
     */
    public static function filenameFrom(?string $disposition): ?string
    {
        if ($disposition === null || $disposition === '') {
            return null;
        }
        if (preg_match("/filename\*=UTF-8''([^;]+)/i", $disposition, $m) === 1) {
            return self::safeBasename(rawurldecode($m[1]));
        }
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m) === 1) {
            return self::safeBasename($m[1]);
        }

        return null;
    }

    /** The last path component, without control characters or separators; never `.`/`..`/empty. */
    public static function safeBasename(string $name): ?string
    {
        $parts = preg_split('#[/\\\\]#', $name);
        $last = is_array($parts) ? (string) end($parts) : '';
        $clean = trim((string) preg_replace('/[\x00-\x1f\x7f]/', '', $last));

        return $clean === '' || $clean === '.' || $clean === '..' ? null : $clean;
    }
}
