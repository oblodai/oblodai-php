<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * v1.1.0: payments()->resolve (судьба недоплаты) и payments()->sendEmail (счёт на e-mail).
 */
final class ResolveTest extends TestCase
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

    public function testResolveAccept(): void
    {
        $client = $this->client(['payment_uuid' => 'p1', 'resolution' => 'accepted', 'amount_kept' => '48.5']);

        $res = $client->payments()->resolve(['uuid' => 'p1', 'action' => 'accept']);

        self::assertSame('accepted', $res['resolution']);
        self::assertSame('https://api.test/v1/payment/resolve', $this->t->calls[0]['url']);
        self::assertSame(['uuid' => 'p1', 'action' => 'accept'], $this->body());
        // resolve может двигать деньги (refund) — уходит с Idempotency-Key.
        self::assertArrayHasKey('Idempotency-Key', $this->t->calls[0]['headers']);
    }

    public function testResolveRefundByOrderIdWithCallerKey(): void
    {
        $client = $this->client(['resolution' => 'refunded', 'uuid' => 'po-1', 'status' => 'check']);

        $res = $client->payments()->resolve([
            'order_id' => 'ord-1001',
            'action' => 'refund',
            'reference' => 'rf-1',
            'idempotency_key' => 'res-key-1',
        ]);

        self::assertSame('refunded', $res['resolution']);
        self::assertSame('res-key-1', $this->t->calls[0]['headers']['Idempotency-Key']);
        $body = $this->body();
        self::assertSame(['order_id' => 'ord-1001', 'action' => 'refund', 'reference' => 'rf-1'], $body);
    }

    public function testSendEmailWithExplicitRecipient(): void
    {
        $client = $this->client(['sent' => true, 'email' => 'buyer@example.com', 'uuid' => 'p1']);

        $res = $client->payments()->sendEmail('p1', null, 'buyer@example.com');

        self::assertTrue($res['sent']);
        self::assertSame('https://api.test/v1/payment/send-email', $this->t->calls[0]['url']);
        self::assertSame(['uuid' => 'p1', 'email' => 'buyer@example.com'], $this->body());
        self::assertArrayNotHasKey('Idempotency-Key', $this->t->calls[0]['headers']);
    }

    public function testSendEmailByOrderIdToPayerEmail(): void
    {
        $client = $this->client(['sent' => true]);

        $client->payments()->sendEmail(null, 'ord-1');

        self::assertSame(['order_id' => 'ord-1'], $this->body());
    }
}
