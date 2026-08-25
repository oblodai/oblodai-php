<?php

declare(strict_types=1);

namespace Oblodai\Log;

use RuntimeException;

/** Writes to STDERR above a level; `OBLODAI_LOG=debug|info|warn|error` selects it from the env. */
final class ConsoleLogger implements Logger
{
    private const ORDER = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];

    /** @var resource */
    private $stream;

    /** @param resource|null $stream */
    public function __construct(private readonly string $level = 'warn', $stream = null)
    {
        if ($stream === null) {
            $stream = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        }
        if ($stream === false) {
            throw new RuntimeException('could not open php://stderr for logging');
        }
        $this->stream = $stream;
    }

    public function debug(string $message, array $fields = []): void
    {
        $this->emit('debug', $message, $fields);
    }

    public function info(string $message, array $fields = []): void
    {
        $this->emit('info', $message, $fields);
    }

    public function warning(string $message, array $fields = []): void
    {
        $this->emit('warn', $message, $fields);
    }

    public function error(string $message, array $fields = []): void
    {
        $this->emit('error', $message, $fields);
    }

    /** @param array<string, mixed> $fields */
    private function emit(string $level, string $message, array $fields): void
    {
        if ((self::ORDER[$level] ?? 0) < (self::ORDER[$this->level] ?? 2)) {
            return;
        }
        $line = sprintf('[oblodai] %s %s', strtoupper($level), $message);
        if ($fields !== []) {
            $line .= ' ' . json_encode(Redactor::redact($fields), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        fwrite($this->stream, $line . "\n");
    }
}
