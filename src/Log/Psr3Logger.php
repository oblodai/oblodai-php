<?php

declare(strict_types=1);

namespace Oblodai\Log;

use Psr\Log\LoggerInterface;

/** Adapter for a PSR-3 logger (Monolog and friends); requires psr/log to be installed. */
final class Psr3Logger implements Logger
{
    public function __construct(private readonly LoggerInterface $inner)
    {
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
