<?php

declare(strict_types=1);

namespace Oblodai\Http;

use Oblodai\Exception\ConnectionException;

/**
 * Встроенный транспорт на cURL (без внешних зависимостей).
 */
final class CurlTransport implements Transport
{
    /**
     * Абсолютный потолок серверной подсказки `Retry-After` (5 минут) — как в остальных SDK
     * (Node/Python/Go). Защита от абсурдных значений: сервер, попросивший подождать сутки,
     * не должен подвешивать вызывающий процесс.
     */
    private const MAX_RETRY_AFTER_SECONDS = 300.0;

    private int $timeoutMs;

    public function __construct(int $timeoutMs = 30000)
    {
        $this->timeoutMs = $timeoutMs;
    }

    /**
     * Разбирает значение заголовка `Retry-After` в секунды.
     *
     * RFC 7231 разрешает ДВЕ формы, и обе встречаются у прокси/балансировщиков перед шлюзом:
     *  - delta-seconds: `Retry-After: 60`;
     *  - HTTP-date:     `Retry-After: Wed, 21 Oct 2026 07:28:00 GMT` — переводится в остаток
     *    относительно текущего момента.
     * Раньше SDK понимал только первую и молча выбрасывал вторую, откатываясь на собственный
     * backoff — то есть бил в 429 раньше, чем просили.
     *
     * Результат зажат в [0; 300] секунд: дата в прошлом даёт 0 (повторяем сразу), а абсурдно
     * далёкая — потолок. Нераспознанное значение — `null` (вызывающий берёт свой backoff).
     *
     * @internal
     *
     * @param int|null $now отметка времени «сейчас» (для тестов); по умолчанию — time()
     */
    public static function parseRetryAfter(string $value, ?int $now = null): ?float
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }

        if (is_numeric($v)) {
            $seconds = (float) $v;
        } else {
            $base = $now ?? time();
            $at = strtotime($v, $base);
            if ($at === false) {
                return null;
            }
            $seconds = (float) ($at - $base);
        }

        if (!is_finite($seconds)) {
            return null;
        }

        return min(max($seconds, 0.0), self::MAX_RETRY_AFTER_SECONDS);
    }

    /**
     * Таймаут установления соединения — примерно треть от общего (как в остальных SDK).
     *
     * Без него мёртвый или чёрнодырящий хост съедал ВЕСЬ бюджет запроса на одном TCP-хендшейке,
     * и на ретраи времени не оставалось. Треть даёт «не отвечает — быстро мимо», оставляя
     * основную часть бюджета на собственно ответ шлюза.
     *
     * `0` (в cURL — «без ограничения») сохраняем как есть.
     *
     * @internal
     */
    public static function connectTimeoutMs(int $timeoutMs): int
    {
        if ($timeoutMs <= 0) {
            return 0;
        }

        return max(1, intdiv($timeoutMs, 3));
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $ch = curl_init();

        $headerList = [];
        foreach ($headers as $name => $value) {
            $headerList[] = $name . ': ' . $value;
        }

        $retryAfter = null;
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerList,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => self::connectTimeoutMs($this->timeoutMs),
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$retryAfter) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2 && strtolower(trim($parts[0])) === 'retry-after') {
                    $parsed = self::parseRetryAfter($parts[1]);
                    if ($parsed !== null) {
                        $retryAfter = $parsed;
                    }
                }

                return strlen($header);
            },
        ]);

        if ($method !== 'GET' && $body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ConnectionException('Сетевая ошибка при запросе: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return new Response($status, (string) $responseBody, $retryAfter);
    }
}
