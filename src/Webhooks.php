<?php

declare(strict_types=1);

namespace Oblodai;

use Oblodai\Exception\SignatureException;

/**
 * Проверка входящих вебхуков.
 *
 * Подпись вебхука = hex(HMAC-SHA256(webhookSecret, "{X-Webhook-Timestamp}." + сырое_тело)).
 * Секрет — тот, что вернул POST /v1/webhooks (не ключ API).
 *
 * Пробные тела ("is_test": true) НЕ подписаны — проверять их не нужно.
 */
final class Webhooks
{
    /**
     * Проверяет подпись и свежесть вебхука. Возвращает true при успехе, иначе бросает SignatureException.
     *
     * @param string $secret        Секрет из POST /v1/webhooks
     * @param string $rawBody       Сырое тело запроса (не пересериализованное!)
     * @param string $timestamp     Заголовок X-Webhook-Timestamp
     * @param string $signature     Заголовок X-Webhook-Signature
     * @param int           $maxAgeSeconds Окно свежести для replay-защиты (0 отключает). По умолчанию 300.
     * @param callable|null $logger        Необязательный приёмник логов: function(string $level, string $message): void.
     *                                     Логируются только метаданные — секреты/подписи/тела НЕ пишутся.
     *
     * @throws SignatureException
     */
    public static function verify(string $secret, string $rawBody, string $timestamp, string $signature, int $maxAgeSeconds = 300, ?callable $logger = null): bool
    {
        if ($secret === '' || $timestamp === '' || $signature === '') {
            if ($logger !== null) {
                $logger('warning', 'oblodai: webhook verify failed: missing secret/timestamp/signature');
            }
            throw new SignatureException('Отсутствует секрет, timestamp или signature вебхука');
        }

        $expected = Signing::computeWebhookSignature($secret, $timestamp, $rawBody);
        if (!hash_equals($expected, $signature)) {
            if ($logger !== null) {
                $logger('warning', 'oblodai: webhook verify failed: signature mismatch');
            }
            throw new SignatureException('Подпись вебхука не совпадает');
        }

        if ($maxAgeSeconds > 0) {
            if (!ctype_digit($timestamp)) {
                if ($logger !== null) {
                    $logger('warning', 'oblodai: webhook verify failed: invalid timestamp');
                }
                throw new SignatureException('Некорректный timestamp вебхука');
            }
            if (abs(time() - (int) $timestamp) > $maxAgeSeconds) {
                if ($logger !== null) {
                    $logger('warning', 'oblodai: webhook verify failed: too old (replay protection)');
                }
                throw new SignatureException('Вебхук слишком старый (replay-защита)');
            }
        }

        if ($logger !== null) {
            $logger('debug', 'oblodai: webhook signature ok');
        }

        return true;
    }

    /**
     * Проверяет подпись и возвращает разобранное тело вебхука как массив.
     *
     * @param callable|null $logger Необязательный приёмник логов (см. {@see self::verify()}).
     *
     * @return array<string,mixed>
     *
     * @throws SignatureException
     */
    public static function constructEvent(string $secret, string $rawBody, string $timestamp, string $signature, int $maxAgeSeconds = 300, ?callable $logger = null): array
    {
        self::verify($secret, $rawBody, $timestamp, $signature, $maxAgeSeconds, $logger);
        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Является ли тело пробным (незнаковым) вебхуком.
     */
    public static function isTest(string $rawBody): bool
    {
        $decoded = json_decode($rawBody, true);

        return is_array($decoded) && !empty($decoded['is_test']);
    }
}
