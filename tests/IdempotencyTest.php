<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Exception\ConnectionException;
use Oblodai\Http\Response;
use Oblodai\Http\Transport;
use PHPUnit\Framework\TestCase;

/**
 * Идемпотентность повторов: SDK сам подставляет стабильный order_id и шлёт
 * ОДИН и тот же ключ во всех попытках, чтобы повтор не создавал дубль.
 */
final class IdempotencyTest extends TestCase
{
    /** @return array<string,mixed> */
    private function decodeBody(?string $body): array
    {
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testPaymentCreateSuppliesOrderIdWhenMissing(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => ['uuid' => 'p1']]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD']);

        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertArrayHasKey('order_id', $body);
        self::assertIsString($body['order_id']);
        self::assertNotSame('', $body['order_id']);
    }

    public function testPaymentCreateKeepsCallerOrderId(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD', 'order_id' => 'mine-1']);

        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertSame('mine-1', $body['order_id']);
    }

    public function testSameOrderIdAcrossRetries(): void
    {
        // Первая попытка — сетевой сбой, вторая — 200. Обе должны нести один order_id.
        $t = new FlakyTransport(
            new Response(200, json_encode(['state' => 0, 'result' => ['uuid' => 'p1']])),
            1,
        );
        $client = new Client('pub', 'sec', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => 3, 'initial_delay_ms' => 1, 'max_delay_ms' => 2],
        ]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD']);

        self::assertCount(2, $t->calls);
        $first = $this->decodeBody($t->calls[0]['body']);
        $second = $this->decodeBody($t->calls[1]['body']);
        self::assertNotSame('', $first['order_id']);
        self::assertSame($first['order_id'], $second['order_id']);
    }

    public function testTransferToPersonalSuppliesOrderId(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->account()->transferToPersonal(['amount' => '5', 'currency' => 'USDT']);

        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertArrayHasKey('order_id', $body);
        self::assertNotSame('', $body['order_id']);
    }
}

/**
 * Транспорт, который первые $failFirst раз бросает ConnectionException, затем отдаёт ответ.
 * Запоминает ВСЕ попытки (включая упавшие), чтобы сверить одинаковость order_id.
 */
final class FlakyTransport implements Transport
{
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];

    private Response $ok;

    private int $failFirst;

    public function __construct(Response $ok, int $failFirst)
    {
        $this->ok = $ok;
        $this->failFirst = $failFirst;
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if (count($this->calls) <= $this->failFirst) {
            throw new ConnectionException('boom');
        }

        return $this->ok;
    }
}
