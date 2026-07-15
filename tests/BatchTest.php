<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Массовые операции v1.1.0: payments()->createBatch / refundBatch, payouts()->createBatch,
 * batches()->info.
 */
final class BatchTest extends TestCase
{
    private MockTransport $t;

    private function client(Response ...$responses): Client
    {
        $this->t = new MockTransport($responses === [] ? [$this->ok(['batch_id' => 'b1'])] : $responses);

        return new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $this->t, 'retry' => false]);
    }

    /** @param array<string,mixed> $result */
    private function ok(array $result): Response
    {
        return new Response(200, json_encode(['state' => 0, 'result' => $result]));
    }

    /** @return array<string,mixed> */
    private function body(int $i = 0): array
    {
        $decoded = json_decode((string) $this->t->calls[$i]['body'], true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testPaymentCreateBatch(): void
    {
        $client = $this->client($this->ok(['batch_id' => 'b1', 'kind' => 'payment', 'count' => 2, 'status' => 'pending']));

        $res = $client->payments()->createBatch([
            ['amount' => '10', 'currency' => 'USD', 'order_id' => 'a-1'],
            ['amount' => '20', 'currency' => 'EUR', 'order_id' => 'a-2'],
        ]);

        self::assertSame('b1', $res['batch_id']);
        self::assertSame('https://api.test/v1/payment/batch', $this->t->calls[0]['url']);
        $body = $this->body();
        self::assertCount(2, $body['payments']);
        self::assertSame('a-1', $body['payments'][0]['order_id']);
        self::assertSame('continue', $body['on_error']);
        // Батчи двигают деньги — обязателен Idempotency-Key.
        self::assertArrayHasKey('Idempotency-Key', $this->t->calls[0]['headers']);
    }

    public function testRefundBatchWithStopMode(): void
    {
        $client = $this->client();

        $client->payments()->refundBatch(
            [['uuid' => 'p1', 'reference' => 'r-1'], ['uuid' => 'p2', 'reference' => 'r-2']],
            'stop',
        );

        self::assertSame('https://api.test/v1/refund/batch', $this->t->calls[0]['url']);
        $body = $this->body();
        self::assertCount(2, $body['refunds']);
        self::assertSame('stop', $body['on_error']);
        self::assertArrayHasKey('Idempotency-Key', $this->t->calls[0]['headers']);
    }

    public function testPayoutCreateBatchWithCallerIdempotencyKey(): void
    {
        $client = $this->client();

        $client->payouts()->createBatch(
            [['amount' => '5', 'currency' => 'USDT', 'network' => 'tron', 'address' => 'T1', 'order_id' => 'w-1']],
            'continue',
            'my-batch-key',
        );

        self::assertSame('https://api.test/v1/payout/batch', $this->t->calls[0]['url']);
        self::assertSame('my-batch-key', $this->t->calls[0]['headers']['Idempotency-Key']);
        $body = $this->body();
        self::assertSame('w-1', $body['payouts'][0]['order_id']);
    }

    public function testBatchInfo(): void
    {
        $client = $this->client($this->ok(['batch_id' => 'b1', 'status' => 'completed', 'items' => []]));

        $res = $client->batches()->info('b1', 100, 0);

        self::assertSame('completed', $res['status']);
        self::assertSame('https://api.test/v1/batch/info', $this->t->calls[0]['url']);
        self::assertSame(['batch_id' => 'b1', 'limit' => 100, 'offset' => 0], $this->body());
        // Read-only эндпоинт — без Idempotency-Key.
        self::assertArrayNotHasKey('Idempotency-Key', $this->t->calls[0]['headers']);
    }
}
