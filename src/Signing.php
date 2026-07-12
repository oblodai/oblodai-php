<?php

declare(strict_types=1);

namespace Oblodai;

/**
 * Подпись запросов и вебхуков.
 *
 * ВНИМАНИЕ: это два РАЗНЫХ алгоритма.
 *  - Запрос:  hex(HMAC-SHA256(secret, "{ts}\n{METHOD}\n{path}\n{body}"))
 *  - Вебхук:  hex(HMAC-SHA256(secret, "{ts}." + rawBody))
 */
final class Signing
{
    /**
     * Считает подпись запроса. Тело подписывается ровно теми байтами, что уходят в сеть.
     *
     * @param string      $secret Секрет ключа мерчанта
     * @param string      $method HTTP-метод в верхнем регистре (обычно "POST")
     * @param string      $path   Путь запроса с ведущим слэшем, например "/v1/payment"
     * @param string      $body   Сериализованное тело (подписывается как есть)
     * @param string|null $ts     Опционально фиксированный timestamp (для тестов)
     *
     * @return array{0:string,1:string} [timestamp, signature]
     */
    public static function signRequest(string $secret, string $method, string $path, string $body, ?string $ts = null): array
    {
        $ts = $ts ?? (string) time();
        $signingString = $ts . "\n" . $method . "\n" . $path . "\n" . $body;
        $signature = hash_hmac('sha256', $signingString, $secret);

        return [$ts, $signature];
    }

    /**
     * Считает ожидаемую подпись вебхука для заданных timestamp и сырого тела.
     */
    public static function computeWebhookSignature(string $secret, string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }
}
