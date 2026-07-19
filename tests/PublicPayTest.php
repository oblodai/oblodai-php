<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Публичные эндпоинты счёта v1.2.0 (для собственных checkout-страниц, без подписи):
 * payments()->publicGet (GET /v1/pay/{id}) и payments()->publicSelect (POST /v1/pay/{id}/select).
 */
final class PublicPayTest extends TestCase
{
    private MockTransport $t;

    /** @param array<string,mixed> $result */
    private function client(array $result = []): Client
    {
        $this->t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => $result]))]);

        return new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $this->t, 'retry' => false]);
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        $decoded = json_decode((string) $this->t->calls[0]['body'], true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testPublicGetIsUnsigned(): void
    {
        $client = $this->client(['uuid' => 'p1', 'payment_status' => 'check', 'address' => 'T1']);

        $res = $client->payments()->publicGet('p1');

        self::assertSame('check', $res['payment_status']);
        $call = $this->t->calls[0];
        self::assertSame('GET', $call['method']);
        self::assertSame('https://api.test/v1/pay/p1', $call['url']);
        // Публичный эндпоинт: ни подписи, ни ключа идемпотентности.
        self::assertArrayNotHasKey('X-Signature', $call['headers']);
        self::assertArrayNotHasKey('X-Public-Id', $call['headers']);
        self::assertArrayNotHasKey('Idempotency-Key', $call['headers']);
    }

    public function testPublicSelectIsUnsignedPost(): void
    {
        $client = $this->client(['uuid' => 'p1', 'payment_status' => 'check', 'currency' => 'USDT', 'network' => 'tron', 'address' => 'T1']);

        $res = $client->payments()->publicSelect('p1', 'USDT', 'tron');

        self::assertSame('T1', $res['address']);
        $call = $this->t->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.test/v1/pay/p1/select', $call['url']);
        self::assertArrayNotHasKey('X-Signature', $call['headers']);
        self::assertArrayNotHasKey('X-Public-Id', $call['headers']);
        self::assertSame(['currency' => 'USDT', 'network' => 'tron'], $this->body());
    }
}
