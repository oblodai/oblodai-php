<?php

declare(strict_types=1);

namespace Oblodai\Http;

use Oblodai\Exception\ConnectionException;

/**
 * Встроенный транспорт на cURL (без внешних зависимостей).
 */
final class CurlTransport implements Transport
{
    private int $timeoutMs;

    public function __construct(int $timeoutMs = 30000)
    {
        $this->timeoutMs = $timeoutMs;
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
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$retryAfter) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2 && strtolower(trim($parts[0])) === 'retry-after') {
                    $v = trim($parts[1]);
                    if (is_numeric($v)) {
                        $retryAfter = (float) $v;
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
