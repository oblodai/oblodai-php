<?php

declare(strict_types=1);

namespace Oblodai\Log;

/** Logs nothing; the default. */
final class NullLogger implements Logger
{
    public function debug(string $message, array $fields = []): void
    {
    }

    public function info(string $message, array $fields = []): void
    {
    }

    public function warning(string $message, array $fields = []): void
    {
    }

    public function error(string $message, array $fields = []): void
    {
    }
}
