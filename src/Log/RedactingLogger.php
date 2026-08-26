<?php

declare(strict_types=1);

namespace Oblodai\Log;

/**
 * Wraps any logger so that secret-looking fields are masked BEFORE they leave the SDK.
 *
 * The client wraps whatever logger it is handed, including a caller's own implementation. That is
 * the only ordering that holds: a redaction step living inside the SDK's own logger classes does
 * nothing for the logger a caller injects, and "we never log secrets" then depends on every future
 * call site remembering to redact.
 */
final class RedactingLogger implements Logger
{
    public function __construct(private readonly Logger $inner)
    {
    }

    /** Wrap unless it is already wrapped (or logs nothing at all). */
    public static function wrap(?Logger $logger): Logger
    {
        if ($logger === null || $logger instanceof NullLogger) {
            return new NullLogger();
        }

        return $logger instanceof self ? $logger : new self($logger);
    }

    public function debug(string $message, array $fields = []): void
    {
        $this->inner->debug($message, Redactor::redactFields($fields));
    }

    public function info(string $message, array $fields = []): void
    {
        $this->inner->info($message, Redactor::redactFields($fields));
    }

    public function warning(string $message, array $fields = []): void
    {
        $this->inner->warning($message, Redactor::redactFields($fields));
    }

    public function error(string $message, array $fields = []): void
    {
        $this->inner->error($message, Redactor::redactFields($fields));
    }
}
