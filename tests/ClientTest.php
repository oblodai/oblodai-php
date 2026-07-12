<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Exception\ApiException;
use Oblodai\Exception\ConfigException;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function client(MockTransport $t): Client
    {
        return new Client('pub_1', 'sec_1', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);
    }

    public function testSignsAndUnwraps(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => ['uuid' => 'p1', 'url' => 'u']]))]);
        $payment = $this->client($t)->payments()->create(['amount' => '10', 'currency' => 'USD', 'order_id' => 'o1']);

        self::assertSame('p1', $payment['uuid']);
        $h = $t->calls[0]['headers'];
        self::assertSame('pub_1', $h['X-Public-Id']);
        self::assertMatchesRegularExpression('/^\d+$/', $h['X-Timestamp']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h['X-Signature']);
        self::assertSame('https://api.test/v1/payment', $t->calls[0]['url']);
    }

    public function testApiError(): void
    {
        $t = new MockTransport([new Response(409, json_encode(['error' => ['code' => 'payout.insufficient_funds', 'message' => 'no']]))]);
        try {
            $this->client($t)->payouts()->create(['amount' => '5', 'currency' => 'USDT', 'address' => 'T', 'order_id' => 'x']);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('payout.insufficient_funds', $e->getErrorCode());
            self::assertSame(409, $e->getStatusCode());
            self::assertFalse($e->isRetriable());
        }
    }

    public function test429SurfacesMessageAndRetryAfter(): void
    {
        $t = new MockTransport([new Response(429, json_encode(['state' => 1, 'message' => 'rate limit exceeded']), 60.0)]);
        try {
            $this->client($t)->account()->balance();
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('http.429', $e->getErrorCode());
            self::assertSame('rate limit exceeded', $e->getMessage());
            self::assertSame(60.0, $e->getRetryAfter());
            self::assertTrue($e->isRetriable());
        }
    }

    public function test429HonorsRetryAfterAndRetries(): void
    {
        $t = new MockTransport([
            new Response(429, json_encode(['state' => 1, 'message' => 'rate limit exceeded']), 0.0),
            new Response(200, json_encode(['state' => 0, 'result' => ['balance' => ['merchant' => []]]])),
        ]);
        $client = new Client('p', 's', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => 3, 'initial_delay_ms' => 1, 'max_delay_ms' => 5],
        ]);
        $bal = $client->account()->balance();
        self::assertSame([], $bal['balance']['merchant']);
        self::assertCount(2, $t->calls);
    }

    public function testWebhookRegisterBare201(): void
    {
        $t = new MockTransport([new Response(201, json_encode(['endpoint_id' => 'e1', 'url' => 'https://x', 'secret' => 's1']))]);
        $reg = $this->client($t)->webhooks()->register('https://x');
        self::assertSame('s1', $reg['secret']);
        self::assertSame('e1', $reg['endpoint_id']);
    }

    public function testCurrenciesPublicGetUnsigned(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['currencies' => [['symbol' => 'USDT', 'decimals' => 6, 'networks' => []]]]))]);
        $cur = $this->client($t)->rates()->currencies();
        self::assertSame('USDT', $cur[0]['symbol']);
        self::assertSame('GET', $t->calls[0]['method']);
        self::assertSame('https://api.test/v1/currencies', $t->calls[0]['url']);
        self::assertArrayNotHasKey('X-Signature', $t->calls[0]['headers']);
    }

    public function testFromEnv(): void
    {
        putenv('OBLODAI_PUBLIC_ID=pub_env');
        putenv('OBLODAI_SECRET=sec_env');
        putenv('OBLODAI_BASE_URL=https://env.example');
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = Client::fromEnv(['transport' => $t, 'retry' => false]);
        $client->account()->balance();
        self::assertStringStartsWith('https://env.example/', $t->calls[0]['url']);

        putenv('OBLODAI_PUBLIC_ID');
        $this->expectException(ConfigException::class);
        Client::fromEnv();
    }
}
