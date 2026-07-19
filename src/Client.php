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
use Oblodai\Resource\Batches;
use Oblodai\Resource\PaymentLinks;
use Oblodai\Resource\Payments;
use Oblodai\Resource\PayoutLinks;
use Oblodai\Resource\Payouts;
use Oblodai\Resource\Rates;
use Oblodai\Resource\Sandbox;
use Oblodai\Resource\Settings;
use Oblodai\Resource\Splits;
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

    /** Абсолютный потолок паузы по серверному `Retry-After` — 5 минут (как в остальных SDK). */
    private const MAX_RETRY_AFTER_MS = 300000;

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
        $this->baseUrl = self::normalizeBaseUrl($options['base_url'] ?? self::DEFAULT_BASE_URL);

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
            // max_attempts — это ЧИСЛО ПОПЫТОК, а не число повторов: минимум 1, иначе цикл в
            // execute() не выполнится ни разу и на выходе окажется `throw null` — фатальная
            // ошибка вместо исключения (0 и отрицательные значения приходили из конфигов,
            // где «повторы отключены» пробовали выразить нулём; для этого есть 'retry' => false).
            $this->retry = [
                'max_attempts' => max(1, (int) ($r['max_attempts'] ?? 4)),
                'initial_delay_ms' => max(0, (int) ($r['initial_delay_ms'] ?? 500)),
                'max_delay_ms' => max(0, (int) ($r['max_delay_ms'] ?? 30000)),
            ];
        }
    }

    /**
     * Нормализует и проверяет базовый URL.
     *
     * Схема обязана быть `https`: подпись запроса уходит в заголовке `X-Signature`, и по
     * открытому http её видит (и может переиграть) любой посредник на пути. Раньше SDK молча
     * принимал http:// и подписывал запросы в открытый канал.
     *
     * Единственное исключение — loopback (`localhost`, `127.0.0.0/8`, `::1`, домены `*.localhost`):
     * по нему работают локальные стенды вроде `http://localhost:8095`, где трафик не покидает
     * машину.
     *
     * @throws ConfigException если URL не абсолютный либо схема небезопасна
     */
    private static function normalizeBaseUrl(string $raw): string
    {
        $url = rtrim(trim($raw), '/');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme === '' || $host === '') {
            throw new ConfigException(
                'base_url должен быть абсолютным URL со схемой и хостом, например '
                . 'https://api.oblodai.com (получено: "' . $raw . '")'
            );
        }

        if ($scheme === 'https' || ($scheme === 'http' && self::isLoopbackHost($host))) {
            return $url;
        }

        throw new ConfigException(
            'base_url должен использовать https:// — по http подпись запроса (X-Signature) уходит '
            . 'в открытый канал (получено: "' . $raw . '"). Исключение только для локальных '
            . 'стендов на loopback: http://localhost:8095, http://127.0.0.1:8095, http://[::1]:8095'
        );
    }

    /** Loopback-хост, по которому допустим незашифрованный http (локальные стенды). */
    private static function isLoopbackHost(string $host): bool
    {
        $h = trim($host, '[]'); // IPv6 в URL приходит в скобках: http://[::1]:8095

        return $h === 'localhost'
            || $h === '::1'
            || str_ends_with($h, '.localhost')
            || (bool) preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $h);
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

    /** Массовые операции (батчи): прогресс и результаты. С v1.1.0. */
    public function batches(): Batches
    {
        return new Batches($this);
    }

    /**
     * Платёжные ссылки (переиспользуемые, «донатные»). С v1.1.0.
     *
     * Каноническое имя ресурса во всех SDK — payment_links (в идиоматике языка: paymentLinks
     * в PHP/JS, payment_links в Python/Rust, PaymentLinks в Go), чтобы код переносился между
     * языками без переименований. Короткое {@see links()} остаётся документированным алиасом.
     */
    public function paymentLinks(): PaymentLinks
    {
        return new PaymentLinks($this);
    }

    /** Алиас {@see paymentLinks()} — платёжные ссылки. Оба имени поддерживаются и равнозначны. */
    public function links(): PaymentLinks
    {
        return $this->paymentLinks();
    }

    /** Payout-ссылки («крипто-чеки»: выплата без знания кошелька получателя). С v1.1.0. */
    public function payoutLinks(): PayoutLinks
    {
        return new PayoutLinks($this);
    }

    /** Сплит-платежи: правила и настройки. С v1.1.0. */
    public function splits(): Splits
    {
        return new Splits($this);
    }

    /**
     * Sandbox-хелперы для тестовых ключей (симуляция депозита, faucet, reset,
     * журнал/replay вебхуков). С v1.2.0. ТОЛЬКО тестовый код: live-ключ получает
     * 403 sandbox.live_key.
     */
    public function sandbox(): Sandbox
    {
        return new Sandbox($this);
    }

    // ── Внутреннее (используется ресурсами) ──

    /**
     * Подписанный POST-запрос. Возвращает поле result из конверта (или тело без конверта).
     *
     * Всегда массив. Конверт шлюза (`{"state":0,"result":<result>}`, см. apiutil.WriteResult
     * в ядре) не запрещает `result: null` — он получается из любого nil-значения на стороне
     * сервера; телом ответа может прийти и голый `null`. Раньше метод был объявлен `mixed`,
     * а каждый метод ресурса — `: array`, и такой ответ ронял TypeError, который НЕ наследует
     * OblodaiException и потому пролетал мимо catch из README. Теперь `null` — это `[]`.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<mixed>
     */
    public function request(string $path, array $payload = []): array
    {
        return $this->execute('POST', $path, $payload, true);
    }

    /**
     * Подписанный POST-запрос с заголовком Idempotency-Key.
     *
     * Ключ генерируется ОДИН раз до цикла повторов (или берётся переданный), поэтому все
     * внутренние ретраи уходят с одним и тем же значением и бэкенд дедуплицирует их.
     * Заголовок НЕ входит в подпись (подписываются только timestamp/метод/путь/тело).
     *
     * @param array<string,mixed> $payload
     * @param string|null         $idempotencyKey свой ключ (до 255 символов); null — сгенерировать UUID v4
     *
     * @return array<mixed>
     */
    public function requestIdempotent(string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        $key = ($idempotencyKey !== null && $idempotencyKey !== '') ? $idempotencyKey : self::newIdempotencyKey();

        return $this->execute('POST', $path, $payload, true, ['Idempotency-Key' => $key]);
    }

    /**
     * Подписанный GET-запрос (без тела). Подпись считается над пустым телом:
     * "{ts}\nGET\n{path}\n" — та же каноническая строка, что и у POST.
     *
     * @return array<mixed>
     */
    public function requestGet(string $path): array
    {
        return $this->execute('GET', $path, [], true);
    }

    /**
     * Публичный (неподписанный) запрос.
     *
     * @param array<string,mixed> $payload
     *
     * @return array<mixed>
     */
    public function requestPublic(string $path, array $payload = [], string $method = 'POST'): array
    {
        return $this->execute($method, $path, $payload, false);
    }

    /**
     * Тестовый ли это ключ песочницы: public id с префиксом "test_" либо секрет
     * с префиксом "oblodai_test_". Интеграционный код между test и live не меняется —
     * меняется только ключ; хелпер удобен для гардов вида «sandbox-методы только на тесте».
     */
    public static function isTestKey(string $key): bool
    {
        return str_starts_with($key, 'test_') || str_starts_with($key, 'oblodai_test_');
    }

    /** Генерирует ключ идемпотентности (UUID v4). */
    public static function newIdempotencyKey(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // версия 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // вариант RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * @param array<string,mixed>  $payload
     * @param array<string,string> $extraHeaders стабильны между повторами (Idempotency-Key)
     *
     * @return array<mixed>
     */
    private function execute(string $method, string $path, array $payload, bool $signed, array $extraHeaders = []): array
    {
        $attempts = $this->retry ? $this->retry['max_attempts'] : 1;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->log('debug', sprintf('oblodai: -> %s %s (attempt %d/%d)', $method, $path, $attempt, $attempts));
            try {
                return self::toArray($this->once($method, $path, $payload, $signed, $extraHeaders), $method, $path);
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

        // Недостижимо: max_attempts клампится к >= 1, значит тело цикла выполнилось хотя бы раз и
        // либо вернуло результат, либо бросило. Страховка на случай будущих правок: бросаем
        // НАСТОЯЩЕЕ исключение SDK, а не `throw null` (фатальная ошибка мимо catch).
        throw $lastError ?? new ConnectionException(
            sprintf('%s %s: повторы исчерпаны, ответа от шлюза нет', $method, $path)
        );
    }

    /**
     * Приводит поле `result` к массиву — контракт всех методов ресурсов (`: array`).
     *
     * `null` (в т.ч. из nil-среза на стороне шлюза) — это «пусто», а не ошибка: отдаём `[]`.
     * Скаляр в `result` шлюз сегодня не отдаёт ни на одном эндпоинте; если он там окажется,
     * бросаем ApiException (наследник OblodaiException — ловится штатным catch), а НЕ TypeError.
     *
     * @param mixed $result
     *
     * @return array<mixed>
     */
    private static function toArray($result, string $method, string $path): array
    {
        if ($result === null) {
            return [];
        }
        if (is_array($result)) {
            return $result;
        }

        throw new ApiException(
            'response.unexpected_shape',
            sprintf('%s %s: в поле result ожидался объект или список, получено %s', $method, $path, get_debug_type($result)),
            200,
            $result
        );
    }

    /**
     * @param array<string,mixed>  $payload
     * @param array<string,string> $extraHeaders
     *
     * @return mixed
     */
    private function once(string $method, string $path, array $payload, bool $signed, array $extraHeaders = [])
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json;charset=UTF-8',
        ] + $extraHeaders;

        $body = null;
        if ($method !== 'GET') {
            $body = json_encode($payload === [] ? new \stdClass() : $payload, JSON_UNESCAPED_UNICODE);
        }
        if ($signed) {
            // GET подписывается с ПУСТЫМ телом: "{ts}\n{METHOD}\n{path}\n".
            [$ts, $sig] = Signing::signRequest($this->secret, $method, $path, $body ?? '');
            $headers['X-Public-Id'] = $this->publicId;
            $headers['X-Timestamp'] = $ts;
            $headers['X-Signature'] = $sig;
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

    /**
     * Пауза по серверной подсказке `Retry-After`, в миллисекундах.
     *
     * Явное указание сервера уважаем сверх max_delay, но зажимаем в [0; 300000]:
     * абсолютный потолок 5 минут (как в остальных SDK) — чтобы сервер, попросивший подождать
     * сутки, не подвесил процесс; нижняя граница 0 — чтобы отрицательное значение не дало
     * отрицательный usleep (ValueError).
     *
     * @internal вынесено из delayMicros(), чтобы потолок проверялся тестом без реального ожидания
     */
    public static function retryAfterDelayMs(float $retryAfterSeconds): int
    {
        return max(0, min((int) ($retryAfterSeconds * 1000), self::MAX_RETRY_AFTER_MS));
    }

    private function delayMicros(int $attempt, ?float $retryAfterSeconds): int
    {
        $maxDelayMs = $this->retry['max_delay_ms'];
        if ($retryAfterSeconds !== null) {
            return self::retryAfterDelayMs($retryAfterSeconds) * 1000;
        }

        $initial = $this->retry['initial_delay_ms'];
        $base = min($initial * (2 ** ($attempt - 1)), $maxDelayMs);
        // Случайный джиттер, чтобы разнести одновременные повторы (thundering herd).
        $jitter = random_int(0, (int) ($initial / 2));

        return (int) (($base + $jitter) * 1000);
    }
}
