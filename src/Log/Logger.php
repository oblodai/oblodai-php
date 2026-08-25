<?php

declare(strict_types=1);

namespace Oblodai\Log;

/**
 * Minimal structured-logger contract. Field values that carry secrets are redacted before they
 * reach the logger, so a debug log never leaks a key, a signature or a cheque passcode.
 */
interface Logger
{
    /** @param array<string, mixed> $fields */
    public function debug(string $message, array $fields = []): void;

    /** @param array<string, mixed> $fields */
    public function info(string $message, array $fields = []): void;

    /** @param array<string, mixed> $fields */
    public function warning(string $message, array $fields = []): void;

    /** @param array<string, mixed> $fields */
    public function error(string $message, array $fields = []): void;
}
