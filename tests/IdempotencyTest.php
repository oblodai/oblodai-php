<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Exception\ConnectionException;
use Oblodai\Http\Response;
use Oblodai\Http\Transport;
use Oblodai\Signing;
use PHPUnit\Framework\TestCase;

/**
 * Идемпотентность v1.1.0: создающие вызовы уходят с заголовком Idempotency-Key,
 * который генерируется ОДИН раз до цикла повторов и одинаков во всех попытках.
 * order_id больше НЕ подставляется автоматически и передаётся как есть.
 */
final class IdempotencyTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @return array<string,mixed> */
    private function decodeBody(?string $body): array
    {
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testPaymentCreateSendsIdempotencyKeyHeaderAndNoAutoOrderId(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => ['uuid' => 'p1']]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD']);

        $h = $t->calls[0]['headers'];
        self::assertArrayHasKey('Idempotency-Key', $h);
        self::assertMatchesRegularExpression(self::UUID_V4, $h['Idempotency-Key']);

        // Ломающее изменение: order_id НЕ подставляется автоматически.
        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertArrayNotHasKey('order_id', $body);
    }

    public function testPaymentCreateKeepsCallerOrderIdVerbatim(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD', 'order_id' => 'mine-1']);

        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertSame('mine-1', $body['order_id']);
    }

    public function testSameIdempotencyKeyAcrossRetries(): void
    {
        // Первая попытка — сетевой сбой, вторая — 200. Обе должны нести ОДИН Idempotency-Key.
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
        $first = $t->calls[0]['headers']['Idempotency-Key'];
        $second = $t->calls[1]['headers']['Idempotency-Key'];
        self::assertNotSame('', $first);
        self::assertSame($first, $second);
        // И тела попыток идентичны (нет никакой пере-генерации полей).
        self::assertSame($t->calls[0]['body'], $t->calls[1]['body']);
    }

    public function testCallerIdempotencyKeyGoesToHeaderNotBody(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD', 'idempotency_key' => 'my-key-1']);

        self::assertSame('my-key-1', $t->calls[0]['headers']['Idempotency-Key']);
        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertArrayNotHasKey('idempotency_key', $body);
    }

    public function testIdempotencyKeyIsNotPartOfSignature(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD', 'order_id' => 'o1']);

        $call = $t->calls[0];
        // Подпись считается ТОЛЬКО от ts/метода/пути/тела — заголовок в неё не входит.
        [, $expected] = Signing::signRequest('sec', 'POST', '/v1/payment', (string) $call['body'], $call['headers']['X-Timestamp']);
        self::assertSame($expected, $call['headers']['X-Signature']);
    }

    public function testTransferToPersonalSendsHeaderAndNoAutoOrderId(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->account()->transferToPersonal(['amount' => '5', 'currency' => 'USDT']);

        self::assertArrayHasKey('Idempotency-Key', $t->calls[0]['headers']);
        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertArrayNotHasKey('order_id', $body);
    }

    public function testPayoutAndRefundSendHeader(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payouts()->create(['amount' => '5', 'currency' => 'USDT', 'address' => 'T', 'order_id' => 'w-1']);
        $client->payments()->refund(['uuid' => 'p1']);

        self::assertArrayHasKey('Idempotency-Key', $t->calls[0]['headers']);
        self::assertArrayHasKey('Idempotency-Key', $t->calls[1]['headers']);
    }

    public function testPayoutLinkEndpointsDoNotSendHeader(): void
    {
        // /v1/payout/link* не поддерживают Idempotency-Key — дедуп через per-link reference.
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payoutLinks()->create(['currency' => 'BTC', 'network' => 'bitcoin', 'amount' => '0.001', 'expires_in_hours' => 24]);
        $client->payoutLinks()->createBatch([['currency' => 'BTC', 'network' => 'bitcoin', 'amount' => '0.001']]);

        self::assertArrayNotHasKey('Idempotency-Key', $t->calls[0]['headers']);
        self::assertArrayNotHasKey('Idempotency-Key', $t->calls[1]['headers']);
    }
}

/**
 * Транспорт, который первые $failFirst раз бросает ConnectionException, затем отдаёт ответ.
 * Запоминает ВСЕ попытки (включая упавшие), чтобы сверить одинаковость заголовков/тел.
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
