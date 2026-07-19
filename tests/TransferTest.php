<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Переводы пользователям платформы v1.2.0: account()->transferToUser (POST /v1/transfer/to-user)
 * и account()->transferBatch (POST /v1/transfer/batch).
 */
final class TransferTest extends TestCase
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

    public function testTransferToUser(): void
    {
        $client = $this->client([
            'currency' => 'USDT',
            'amount' => '10',
            'to_user_id' => 'a0b1c2d3-0000-4000-8000-000000000001',
            'recipient_balance' => '110',
        ]);

        $res = $client->account()->transferToUser([
            'to_user_id' => 'a0b1c2d3-0000-4000-8000-000000000001',
            'amount' => '10',
            'currency' => 'USDT',
            'order_id' => 'tr-1',
        ]);

        self::assertSame('110', $res['recipient_balance']);
        $call = $this->t->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.test/v1/transfer/to-user', $call['url']);
        // Двигает деньги — подписано и с Idempotency-Key.
        self::assertArrayHasKey('X-Signature', $call['headers']);
        self::assertArrayHasKey('Idempotency-Key', $call['headers']);
        $body = $this->body();
        self::assertSame('a0b1c2d3-0000-4000-8000-000000000001', $body['to_user_id']);
        self::assertSame('10', $body['amount']);
        self::assertSame('USDT', $body['currency']);
        self::assertSame('tr-1', $body['order_id']);
    }

    public function testTransferToUserWithCallerIdempotencyKey(): void
    {
        $client = $this->client(['to_user_id' => 'u1']);

        $client->account()->transferToUser([
            'to_user_id' => 'u1',
            'amount' => '1',
            'currency' => 'USDT',
            'idempotency_key' => 'my-transfer-key',
        ]);

        // Ключ уходит заголовком, НЕ в тело.
        self::assertSame('my-transfer-key', $this->t->calls[0]['headers']['Idempotency-Key']);
        self::assertArrayNotHasKey('idempotency_key', $this->body());
    }

    public function testTransferBatch(): void
    {
        $client = $this->client(['batch_id' => 'b1', 'kind' => 'transfer', 'count' => 2, 'status' => 'pending']);

        $res = $client->account()->transferBatch([
            ['to_user_id' => 'u1', 'amount' => '5', 'currency' => 'USDT', 'order_id' => 't-1'],
            ['to_user_id' => 'u2', 'amount' => '7', 'currency' => 'USDT', 'order_id' => 't-2'],
        ]);

        self::assertSame('b1', $res['batch_id']);
        self::assertSame('https://api.test/v1/transfer/batch', $this->t->calls[0]['url']);
        self::assertArrayHasKey('X-Signature', $this->t->calls[0]['headers']);
        self::assertArrayHasKey('Idempotency-Key', $this->t->calls[0]['headers']);
        $body = $this->body();
        self::assertCount(2, $body['transfers']);
        self::assertSame('u2', $body['transfers'][1]['to_user_id']);
        self::assertSame('continue', $body['on_error']);
    }

    public function testTransferBatchWithStopModeAndCallerKey(): void
    {
        $client = $this->client(['batch_id' => 'b2']);

        $client->account()->transferBatch(
            [['to_user_id' => 'u1', 'amount' => '5', 'currency' => 'USDT', 'order_id' => 't-1']],
            'stop',
            'my-batch-key',
        );

        self::assertSame('my-batch-key', $this->t->calls[0]['headers']['Idempotency-Key']);
        self::assertSame('stop', $this->body()['on_error']);
    }
}
