<?php

declare(strict_types=1);

namespace Oblodai\Exception;

/**
 * Сетевая ошибка или таймаут. Как правило, безопасно повторить с backoff.
 *
 * Помните: таймаут НЕ значит, что операция не прошла — благодаря идемпотентности по order_id
 * повтор безопасен.
 */
final class ConnectionException extends OblodaiException
{
    public function isRetriable(): bool
    {
        return true;
    }
}
