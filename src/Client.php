<?php

declare(strict_types=1);

namespace Oblodai;

use Oblodai\Exception\ApiException;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\ConnectionException;
use Oblodai\Http\CurlTransport;
use Oblodai\Http\Response;
use Oblodai\Http\Transport;
use Oblodai\Resource\Account;
use Oblodai\Resource\Payments;
use Oblodai\Resource\Payouts;
use Oblodai\Resource\Rates;
use Oblodai\Resource\Settings;
use Oblodai\Resource\Wallets;
use Oblodai\Resource\WebhooksResource;

/**
 * Клиент Oblodai API.
 *
 * ```php
 * $client = new Oblodai\Client($publicId, $secret);          // или Oblodai\Client::fromEnv()
 * $payment = $client->payments()->create([
 *     'amount' => '10', 'currency' => 'USD', 'order_id' => 'order-1',
 *     'to_currency' => 'USDT', 'network' => 'tron',
 * ]);
 * echo $payment['url']; // hosted-страница оплаты
 * ```
 */
final class Client
{
    public const ENV_PUBLIC_ID = 'OBLODAI_PUBLIC_ID';
    public const ENV_SECRET = 'OBLODAI_SECRET';
    public const ENV_BASE_URL = 'OBLODAI_BASE_URL';

    /** Уровень логирования из окружения: debug|info|warning|error. */
    public const ENV_LOG = 'OBLODAI_LOG';

    private const DEFAULT_BASE_URL = 'https://api.oblodai.com';

    /** @var array<string,int> Порядок уровней логирования. */
    private const LOG_LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    private string $publicId;
    private string $secret;
    private string $baseUrl;
    private Transport $transport;

    /** @var callable|null Приёмник логов: function(string $level, string $message): void. */
    private $logger;

    /** @var int Минимальный уровень логирования (0 = логирование отключено). */
    private int $logLevel = 0;

    /** @var array{max_attempts:int,initial_delay_ms:int,max_delay_ms:int}|null */
    private ?array $retry;

    /**
     * @param array{
     *   base_url?:string,
     *   timeout_ms?:int,
     *   retry?:array{max_attempts?:int,initial_delay_ms?:int,max_delay_ms?:int}|false,
     *   transport?:Transport,
     *   logger?:callable
     * } $options
     */
    public function __construct(string $publicId, string $secret, array $options = [])
    {
        if ($publicId === '') {
            throw new ConfigException('public_id обязателен');
        }
        if ($secret === '') {
            throw new ConfigException('secret обязателен');
        }

        $this->publicId = $publicId;
        $this->secret = $secret;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');

        $this->transport = $options['transport'] ?? new CurlTransport($options['timeout_ms'] ?? 30000);

        // Опциональное логирование: явный callable из конфига либо fallback на error_log()
        // при заданном OBLODAI_LOG. Секреты/подписи/тела НЕ логируются — только метаданные.
        $envLevelRaw = getenv(self::ENV_LOG);
        $envLevel = (is_string($envLevelRaw) && isset(self::LOG_LEVELS[strtolower($envLevelRaw)]))
            ? self::LOG_LEVELS[strtolower($envLevelRaw)]
            : 0;

        $logger = $options['logger'] ?? null;
        if (is_callable($logger)) {
            $this->logger = $logger;
            $this->logLevel = $envLevel !== 0 ? $envLevel : self::LOG_LEVELS['debug'];
        } elseif ($envLevel !== 0) {
            $this->logger = null;
            $this->logLevel = $envLevel;
        }

        if (($options['retry'] ?? null) === false) {
            $this->retry = null;
        } else {
            $r = $options['retry'] ?? [];
            $this->retry = [
                'max_attempts' => $r['max_attempts'] ?? 4,
                'initial_delay_ms' => $r['initial_delay_ms'] ?? 500,
                'max_delay_ms' => $r['max_delay_ms'] ?? 30000,
            ];
        }
    }

    /**
     * Создаёт клиента из переменных окружения:
     * OBLODAI_PUBLIC_ID и OBLODAI_SECRET (обязательны), OBLODAI_BASE_URL (необязательна).
     *
     * @param array{base_url?:string,timeout_ms?:int,retry?:mixed,transport?:Transport} $options
     */
    public static function fromEnv(array $options = []): self
    {
        $publicId = getenv(self::ENV_PUBLIC_ID);
        $secret = getenv(self::ENV_SECRET);
        if ($publicId === false || $publicId === '') {
            throw new ConfigException('переменная окружения ' . self::ENV_PUBLIC_ID . ' не задана');
        }
        if ($secret === false || $secret === '') {
            throw new ConfigException('переменная окружения ' . self::ENV_SECRET . ' не задана');
        }

        $envBase = getenv(self::ENV_BASE_URL);
        if (!isset($options['base_url']) && is_string($envBase) && $envBase !== '') {
            $options['base_url'] = $envBase;
        }

        /** @phpstan-ignore-next-line */
        return new self($publicId, $secret, $options);
    }

    // ── Ресурсы ──

    public function payments(): Payments
    {
        return new Payments($this);
    }

    public function payouts(): Payouts
    {
        return new Payouts($this);
    }

    public function wallets(): Wallets
    {
        return new Wallets($this);
    }

    public function account(): Account
    {
        return new Account($this);
    }

    public function webhooks(): WebhooksResource
    {
        return new WebhooksResource($this);
    }

    public function settings(): Settings
    {
        return new Settings($this);
    }

    public function rates(): Rates
    {
        return new Rates($this);
    }

    // ── Внутреннее (используется ресурсами) ──

    /**
     * Подписанный POST-запрос. Возвращает поле result из конверта (или тело без конверта).
     *
     * @param array<string,mixed> $payload
     *
     * @return mixed
     */
    public function request(string $path, array $payload = [])
    {
        return $this->execute('POST', $path, $payload, true);
    }

    /**
     * Публичный (неподписанный) запрос.
     *
     * @param array<string,mixed> $payload
     *
     * @return mixed
     */
    public function requestPublic(string $path, array $payload = [], string $method = 'POST')
    {
        return $this->execute($method, $path, $payload, false);
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return mixed
     */
    private function execute(string $method, string $path, array $payload, bool $signed)
    {
        $attempts = $this->retry ? $this->retry['max_attempts'] : 1;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->log('debug', sprintf('oblodai: -> %s %s (attempt %d/%d)', $method, $path, $attempt, $attempts));
            try {
                return $this->once($method, $path, $payload, $signed);
            } catch (ApiException $e) {
                $lastError = $e;
                if (!$e->isRetriable() || $attempt === $attempts || $this->retry === null) {
                    $this->log('warning', sprintf('oblodai: %s %s failed: %d %s', $method, $path, $e->getStatusCode(), $e->getErrorCode()));
                    throw $e;
                }
                $delayMicros = $this->delayMicros($attempt, $e->getRetryAfter());
                $reason = $e->getStatusCode() === 429 ? '429 rate limit' : '5xx';
                $this->log('warning', sprintf('oblodai: retrying %s %s in %dms (%s; attempt %d/%d)', $method, $path, intdiv($delayMicros, 1000), $reason, $attempt + 1, $attempts));
                usleep($delayMicros);
            } catch (ConnectionException $e) {
                $lastError = $e;
                if ($attempt === $attempts || $this->retry === null) {
                    $this->log('warning', sprintf('oblodai: %s %s failed: network error', $method, $path));
                    throw $e;
                }
                $delayMicros = $this->delayMicros($attempt, null);
                $this->log('warning', sprintf('oblodai: retrying %s %s in %dms (network; attempt %d/%d)', $method, $path, intdiv($delayMicros, 1000), $attempt + 1, $attempts));
                usleep($delayMicros);
            }
        }

        // недостижимо
        throw $lastError;
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return mixed
     */
    private function once(string $method, string $path, array $payload, bool $signed)
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json;charset=UTF-8',
        ];

        $body = null;
        if ($method !== 'GET') {
            $body = json_encode($payload === [] ? new \stdClass() : $payload, JSON_UNESCAPED_UNICODE);
            if ($signed) {
                [$ts, $sig] = Signing::signRequest($this->secret, $method, $path, $body);
                $headers['X-Public-Id'] = $this->publicId;
                $headers['X-Timestamp'] = $ts;
                $headers['X-Signature'] = $sig;
            }
        }

        $start = microtime(true);
        $response = $this->transport->send($method, $url, $headers, $body);
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);
        $this->log('debug', sprintf('oblodai: <- %d %s %s %dms', $response->status, $method, $path, $elapsedMs));

        return $this->parseResponse($response);
    }

    /**
     * Пишет строку лога, если её уровень не ниже настроенного минимума.
     * НИКОГДА не принимает секреты/подписи/тела — только метаданные запроса.
     */
    private function log(string $level, string $message): void
    {
        if ($this->logLevel === 0) {
            return;
        }
        $lvl = self::LOG_LEVELS[$level] ?? self::LOG_LEVELS['error'];
        if ($lvl < $this->logLevel) {
            return;
        }

        if ($this->logger !== null) {
            ($this->logger)($level, $message);

            return;
        }

        error_log($message);
    }

    /**
     * @return mixed
     *
     * @throws ApiException
     */
    private function parseResponse(Response $response)
    {
        $status = $response->status;
        $text = $response->body;
        $retryAfter = $response->retryAfter;

        $parsed = $text === '' ? [] : json_decode($text, true);
        if ($text !== '' && $parsed === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new ApiException('response.not_json', "ответ не является JSON (HTTP {$status})", $status, $text, $retryAfter);
        }

        // Конверт ошибки {"error":{"code","message"}}.
        if (is_array($parsed) && isset($parsed['error']) && is_array($parsed['error'])) {
            $code = $parsed['error']['code'] ?? 'unknown';
            $message = $parsed['error']['message'] ?? 'Неизвестная ошибка';

            throw new ApiException((string) $code, (string) $message, $status, $parsed, $retryAfter);
        }

        // Не-2xx без конверта ошибки (сюда попадает и 429: {"state":1,"message":"rate limit exceeded"}).
        if ($status < 200 || $status >= 300) {
            $message = (is_array($parsed) && isset($parsed['message']) && is_string($parsed['message']))
                ? $parsed['message']
                : "HTTP {$status}";

            throw new ApiException("http.{$status}", $message, $status, $parsed, $retryAfter);
        }

        // Успешный конверт {"state":0,"result":...}.
        if (is_array($parsed) && array_key_exists('result', $parsed) && ($parsed['state'] ?? null) === 0) {
            return $parsed['result'];
        }

        // Ответ без конверта (напр. POST /v1/webhooks → bare {endpoint_id,url,secret} с 201).
        return $parsed;
    }

    private function delayMicros(int $attempt, ?float $retryAfterSeconds): int
    {
        $maxDelayMs = $this->retry['max_delay_ms'];
        if ($retryAfterSeconds !== null) {
            // Явное указание сервера (Retry-After) уважаем сверх max_delay:
            // клампим лишь абсолютным потолком 300000 мс, чтобы Retry-After: 60 ждал ~60с.
            $ms = min((int) ($retryAfterSeconds * 1000), 300000);

            return $ms * 1000;
        }

        $initial = $this->retry['initial_delay_ms'];
        $base = min($initial * (2 ** ($attempt - 1)), $maxDelayMs);
        // Случайный джиттер, чтобы разнести одновременные повторы (thundering herd).
        $jitter = random_int(0, (int) ($initial / 2));

        return (int) (($base + $jitter) * 1000);
    }
}
