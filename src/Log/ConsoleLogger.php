<?php

declare(strict_types=1);

namespace Oblodai\Log;

use Oblodai\Exception\ConfigException;

/**
 * Writes to STDERR above a level; `OBLODAI_LOG=debug|info|warn|error` selects it from the env.
 *
 * Under a web SAPI there is no `STDERR` constant, so the logger opens `php://stderr` itself — and
 * closes it again when it is collected, which the CLI's own STDERR must never be.
 */
final class ConsoleLogger implements Logger
{
    private const ORDER = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];

    /** @var resource */
    private $stream;

    /** True when this instance opened the stream and therefore owns closing it. */
    private readonly bool $ownsStream;

    /** @param resource|null $stream */
    public function __construct(private readonly string $level = 'warn', $stream = null)
    {
        $this->ownsStream = $stream === null && !defined('STDERR');
        if ($stream === null) {
            $stream = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        }
        if ($stream === false) {
            throw new ConfigException(
                ConfigException::BAD_CONFIG,
                'could not open php://stderr for logging; pass a stream or a logger explicitly',
                'logger'
            );
        }
        $this->stream = $stream;
    }

    public function __destruct()
    {
        if ($this->ownsStream) {
            fclose($this->stream);
        }
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
