<?php

declare(strict_types=1);

namespace Oblodai\Http;

/**
 * Ответ HTTP-транспорта: статус, тело и заголовок Retry-After (если был).
 */
final class Response
{
    public int $status;
    public string $body;

    /** @var float|null Значение Retry-After в секундах (для 429), либо null. */
    public ?float $retryAfter;

    public function __construct(int $status, string $body, ?float $retryAfter = null)
    {
        $this->status = $status;
        $this->body = $body;
        $this->retryAfter = $retryAfter;
    }
}
