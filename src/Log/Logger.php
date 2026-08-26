<?php

declare(strict_types=1);

namespace Oblodai\Log;

/**
 * Minimal structured-logger contract.
 *
 * The client wraps whatever logger it is given in {@see RedactingLogger}, so an implementation of
 * this interface — including your own — never receives a field whose key looks like a secret, a
 * signature, a token or a passcode: those arrive as `[redacted]`. The SDK still never puts request
 * or response BODIES into a log field, so nothing has to be trusted to spot a secret by its value.
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
