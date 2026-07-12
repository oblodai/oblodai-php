<?php

declare(strict_types=1);

namespace Oblodai\Http;

use Oblodai\Exception\ConnectionException;

/**
 * Абстракция HTTP-клиента. Реализуйте, чтобы подставить свой транспорт (Guzzle/PSR-18/мок).
 * По умолчанию используется {@see CurlTransport}.
 */
interface Transport
{
    /**
     * Выполняет HTTP-запрос.
     *
     * @param string               $method  "GET" | "POST"
     * @param string               $url     Полный URL
     * @param array<string,string> $headers Заголовки (имя => значение)
     * @param string|null          $body    Тело (для POST) или null (для GET)
     *
     * @throws ConnectionException при сетевом сбое/таймауте
     */
    public function send(string $method, string $url, array $headers, ?string $body): Response;
}
