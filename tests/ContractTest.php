<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Exception\ApiException;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Контракт клиента: схема базового URL, форма возвращаемых данных и границы настроек повторов.
 */
final class ContractTest extends TestCase
{
    /** @param array<int,Response> $responses */
    private function client(array $responses, string $baseUrl = 'https://api.test'): Client
    {
        return new Client('pub', 'sec', [
            'base_url' => $baseUrl,
            'transport' => new MockTransport($responses),
            'retry' => false,
        ]);
    }

    // ── Схема базового URL ──

    /** https — штатный случай. */
    public function testHttpsBaseUrlAccepted(): void
    {
        $client = $this->client([new Response(200, json_encode(['state' => 0, 'result' => []]))], 'https://api.oblodai.com');

        self::assertSame([], $client->account()->balance());
    }

    /**
     * http на ВНЕШНИЙ хост запрещён: подпись запроса уходит заголовком X-Signature, и по
     * открытому каналу её видит любой посредник.
     */
    public function testPlainHttpBaseUrlRejected(): void
    {
        foreach (['http://api.oblodai.com', 'http://192.168.1.10:8095', 'http://evil.example'] as $url) {
            try {
                new Client('pub', 'sec', ['base_url' => $url]);
                self::fail('ожидалась ConfigException для ' . $url);
            } catch (ConfigException $e) {
                self::assertStringContainsString('https', $e->getMessage());
                // Ошибка конфигурации — часть иерархии SDK, ловится общим catch из README.
                self::assertInstanceOf(OblodaiException::class, $e);
            }
        }
    }

    /**
     * Loopback — обязательное исключение: на нём работают локальные стенды, включая
     * http://localhost:8095. Сломать его нельзя.
     */
    public function testLoopbackHttpAllowed(): void
    {
        $urls = [
            'http://localhost:8095',
            'http://127.0.0.1:8095',
            'http://127.0.0.1',
            'http://[::1]:8095',
            'http://stand.localhost:8095',
        ];

        foreach ($urls as $url) {
            $client = new Client('pub', 'sec', [
                'base_url' => $url,
                'transport' => $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => ['ok' => true]]))]),
                'retry' => false,
            ]);

            self::assertSame(['ok' => true], $client->account()->balance(), $url);
            self::assertStringStartsWith($url, $t->calls[0]['url']);
        }
    }

    /** Хвостовой слэш срезается, как и раньше, — путь ресурса уже начинается со слэша. */
    public function testTrailingSlashTrimmed(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test/', 'transport' => $t, 'retry' => false]);
        $client->account()->balance();

        self::assertSame('https://api.test/v1/balance', $t->calls[0]['url']);
    }

    /** Относительный адрес без схемы — тоже ошибка конфигурации, а не «почти работает». */
    public function testBaseUrlWithoutSchemeRejected(): void
    {
        $this->expectException(ConfigException::class);
        new Client('pub', 'sec', ['base_url' => 'api.oblodai.com']);
    }

    /** Схема по умолчанию (без base_url) — https. */
    public function testDefaultBaseUrlIsHttps(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        (new Client('pub', 'sec', ['transport' => $t, 'retry' => false]))->account()->balance();

        self::assertStringStartsWith('https://', $t->calls[0]['url']);
    }

    // ── Форма результата ──

    /**
     * Главный кейс: {"state":0,"result":null} проходит через метод ресурса, объявленный `: array`,
     * и НЕ бросает TypeError (тот не наследует OblodaiException и пролетал бы мимо штатного catch).
     */
    public function testNullResultBecomesEmptyArrayAndNeverThrowsTypeError(): void
    {
        $client = $this->client([new Response(200, json_encode(['state' => 0, 'result' => null]))]);

        self::assertSame([], $client->account()->balance());
    }

    /** То же для POST-ресурса с телом и для публичного (неподписанного) запроса. */
    public function testNullResultOnPostAndPublicRequests(): void
    {
        $body = json_encode(['state' => 0, 'result' => null]);

        self::assertSame([], $this->client([new Response(200, $body)])->payments()->create([
            'amount' => '10', 'currency' => 'USD', 'order_id' => 'o1',
        ]));
        self::assertSame([], $this->client([new Response(200, $body)])->links()->publicGet('lnk_1'));
    }

    /** Голое тело `null` (без конверта) — тоже пустой массив, а не TypeError. */
    public function testBareNullBodyBecomesEmptyArray(): void
    {
        self::assertSame([], $this->client([new Response(200, 'null')])->account()->balance());
    }

    /** Пустое тело (204 и подобные) остаётся пустым массивом. */
    public function testEmptyBodyBecomesEmptyArray(): void
    {
        self::assertSame([], $this->client([new Response(200, '')])->account()->balance());
    }

    /**
     * Скаляр в result шлюз сегодня не отдаёт, но если бы отдал — это ApiException (ловится
     * штатным catch из README), а не TypeError мимо иерархии SDK.
     */
    public function testScalarResultRaisesApiExceptionNotTypeError(): void
    {
        $client = $this->client([new Response(200, json_encode(['state' => 0, 'result' => 'oops']))]);

        try {
            $client->account()->balance();
            self::fail('ожидалась ApiException');
        } catch (ApiException $e) {
            self::assertSame('response.unexpected_shape', $e->getErrorCode());
            self::assertInstanceOf(OblodaiException::class, $e);
        }
    }

    // ── Границы настроек повторов ──

    /**
     * max_attempts = 0 раньше давал `throw null` — фатальную ошибку вместо исключения:
     * цикл повторов не выполнялся ни разу. Теперь значение клампится к 1 (одна попытка).
     */
    public function testZeroMaxAttemptsClampedToSingleAttempt(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => ['ok' => true]]))]);
        $client = new Client('pub', 'sec', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => 0],
        ]);

        self::assertSame(['ok' => true], $client->account()->balance());
        self::assertCount(1, $t->calls);
    }

    /** При нуле попыток и ошибке шлюза наружу выходит нормальная ApiException. */
    public function testZeroMaxAttemptsStillRaisesApiExceptionOnError(): void
    {
        $t = new MockTransport([new Response(500, json_encode(['error' => ['code' => 'internal', 'message' => 'boom']]))]);
        $client = new Client('pub', 'sec', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => 0],
        ]);

        try {
            $client->account()->balance();
            self::fail('ожидалась ApiException');
        } catch (ApiException $e) {
            self::assertSame(500, $e->getStatusCode());
            self::assertCount(1, $t->calls, 'ноль попыток не должен превращаться в повторы');
        }
    }

    /**
     * Потолок ожидания по серверному `Retry-After` — 300 секунд, и он клампится ДВАЖДЫ:
     * при разборе заголовка в транспорте (CurlTransportTest) и здесь, при расчёте паузы, —
     * чтобы подсказка от стороннего транспорта тоже не подвесила процесс на сутки.
     */
    public function testRetryAfterDelayIsCappedAtFiveMinutes(): void
    {
        self::assertSame(60000, Client::retryAfterDelayMs(60.0));
        self::assertSame(1500, Client::retryAfterDelayMs(1.5));
        self::assertSame(300000, Client::retryAfterDelayMs(86400.0), 'сутки ожидания зажимаются потолком');
        self::assertSame(300000, Client::retryAfterDelayMs(300.0));
        self::assertSame(0, Client::retryAfterDelayMs(-5.0), 'отрицательное значение не даёт отрицательный usleep');
    }

    /** Отрицательное значение клампится так же. */
    public function testNegativeMaxAttemptsClamped(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => -3],
        ]);

        self::assertSame([], $client->account()->balance());
        self::assertCount(1, $t->calls);
    }
}
