<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Payout-ссылки («крипто-чеки») v1.1.0: management (ключ выплат, подписано, БЕЗ Idempotency-Key)
 * и публичный claim (без подписи вовсе).
 */
final class PayoutLinksTest extends TestCase
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

    public function testCreateIsSignedButWithoutIdempotencyKey(): void
    {
        $client = $this->client(['link_id' => 'l1', 'status' => 'funded', 'claim_token' => 'tok', 'claim_url' => 'https://pay.test/claim/tok']);

        $res = $client->payoutLinks()->create([
            'currency' => 'BTC',
            'network' => 'bitcoin',
            'amount' => '0.005',
            'reference' => 'bonus-42',
            'expires_in_hours' => 720,
        ]);

        self::assertSame('tok', $res['claim_token']);
        self::assertSame('https://api.test/v1/payout/link', $this->t->calls[0]['url']);
        $h = $this->t->calls[0]['headers'];
        self::assertArrayHasKey('X-Signature', $h);
        // Дедуп у payout-ссылок — reference, заголовок не поддерживается.
        self::assertArrayNotHasKey('Idempotency-Key', $h);
        self::assertSame(720, $this->body()['expires_in_hours']);
    }

    public function testCreateBatch(): void
    {
        $client = $this->client(['created' => 1, 'total' => 1, 'results' => [['ok' => true]]]);

        $res = $client->payoutLinks()->createBatch([
            ['currency' => 'USDT', 'network' => 'tron', 'amount' => '5', 'reference' => 'r-1'],
        ]);

        self::assertSame(1, $res['created']);
        self::assertSame('https://api.test/v1/payout/link/batch', $this->t->calls[0]['url']);
        self::assertCount(1, $this->body()['links']);
        self::assertArrayNotHasKey('Idempotency-Key', $this->t->calls[0]['headers']);
    }

    public function testListInfoCancel(): void
    {
        $client = $this->client(['links' => []]);
        $client->payoutLinks()->list(50, 10);
        self::assertSame('https://api.test/v1/payout/link/list', $this->t->calls[0]['url']);
        self::assertSame(['limit' => 50, 'offset' => 10], $this->body());

        $client = $this->client(['link_id' => 'l1', 'status' => 'claimed']);
        $client->payoutLinks()->info('l1');
        self::assertSame('https://api.test/v1/payout/link/info', $this->t->calls[0]['url']);
        self::assertSame(['link_id' => 'l1'], $this->body());

        $client = $this->client(['link_id' => 'l1', 'status' => 'cancelled']);
        $res = $client->payoutLinks()->cancel('l1');
        self::assertSame('cancelled', $res['status']);
        self::assertSame('https://api.test/v1/payout/link/cancel', $this->t->calls[0]['url']);
    }

    public function testClaimInfoIsUnsignedGet(): void
    {
        $client = $this->client(['status' => 'funded', 'claimable' => true, 'amount' => '0.005']);

        $res = $client->payoutLinks()->claimInfo('Xk3v_token-43chars');

        self::assertTrue($res['claimable']);
        $call = $this->t->calls[0];
        self::assertSame('GET', $call['method']);
        self::assertSame('https://api.test/v1/claim/Xk3v_token-43chars', $call['url']);
        self::assertArrayNotHasKey('X-Signature', $call['headers']);
        self::assertArrayNotHasKey('X-Public-Id', $call['headers']);
        self::assertNull($call['body']);
    }

    public function testClaimIsUnsignedPost(): void
    {
        $client = $this->client(['status' => 'claimed', 'payout_id' => 'po-1', 'address' => 'bc1q']);

        $res = $client->payoutLinks()->claim('Xk3v_token-43chars', 'bc1q', 'memo-1');

        self::assertSame('po-1', $res['payout_id']);
        $call = $this->t->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.test/v1/claim/Xk3v_token-43chars', $call['url']);
        self::assertArrayNotHasKey('X-Signature', $call['headers']);
        self::assertArrayNotHasKey('X-Public-Id', $call['headers']);
        self::assertSame(['address' => 'bc1q', 'memo' => 'memo-1'], $this->body());
    }
}
