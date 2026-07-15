<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Платёжные ссылки v1.1.0: management (подписано) + публичные publicGet/checkout (без подписи).
 */
final class PaymentLinksTest extends TestCase
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

    public function testCreate(): void
    {
        $client = $this->client(['link_id' => 'l1', 'url' => 'https://pay.test/link/l1']);

        $res = $client->links()->create(['amount_mode' => 'open', 'currency' => 'USD', 'title' => 'Donate']);

        self::assertSame('l1', $res['link_id']);
        self::assertSame('https://api.test/v1/payment/link', $this->t->calls[0]['url']);
        self::assertArrayHasKey('X-Signature', $this->t->calls[0]['headers']);
        self::assertArrayNotHasKey('Idempotency-Key', $this->t->calls[0]['headers']);
        self::assertSame('open', $this->body()['amount_mode']);
    }

    public function testPaymentLinksIsAliasOfLinks(): void
    {
        $client = $this->client(['items' => []]);
        $client->paymentLinks()->list(20, 0);

        self::assertSame('https://api.test/v1/payment/link/list', $this->t->calls[0]['url']);
        self::assertSame(['limit' => 20, 'offset' => 0], $this->body());
    }

    public function testInfoAndToggle(): void
    {
        $client = $this->client(['link_id' => 'l1', 'payments' => []]);
        $client->links()->info('l1');
        self::assertSame('https://api.test/v1/payment/link/info', $this->t->calls[0]['url']);
        self::assertSame(['link_id' => 'l1'], $this->body());

        $client = $this->client(['link_id' => 'l1', 'active' => false]);
        $res = $client->links()->toggle('l1', false);
        self::assertFalse($res['active']);
        self::assertSame('https://api.test/v1/payment/link/toggle', $this->t->calls[0]['url']);
        self::assertSame(['link_id' => 'l1', 'active' => false], $this->body());
    }

    public function testPublicGetIsUnsigned(): void
    {
        $client = $this->client(['link_id' => 'l1', 'active' => true]);

        $client->links()->publicGet('l1');

        $call = $this->t->calls[0];
        self::assertSame('GET', $call['method']);
        self::assertSame('https://api.test/v1/link/l1', $call['url']);
        self::assertArrayNotHasKey('X-Signature', $call['headers']);
    }

    public function testCheckoutIsUnsignedPost(): void
    {
        $client = $this->client(['uuid' => 'p1', 'url' => 'https://pay.test/pay/p1']);

        $res = $client->links()->checkout('l1', ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron']);

        self::assertSame('p1', $res['uuid']);
        $call = $this->t->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.test/v1/link/l1/checkout', $call['url']);
        self::assertArrayNotHasKey('X-Signature', $call['headers']);
        self::assertSame('10', $this->body()['amount']);
    }
}
