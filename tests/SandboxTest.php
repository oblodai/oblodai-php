<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

final class SandboxTest extends TestCase
{
    private function client(MockTransport $t): Client
    {
        return new Client('test_pub', 'oblodai_test_sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);
    }

    private static function ok(array $result): Response
    {
        return new Response(200, json_encode(['state' => 0, 'result' => $result]));
    }

    public function testSimulateDeposit(): void
    {
        $t = new MockTransport([self::ok(['invoice_id' => 'inv-1', 'txid' => 'tx-1', 'amount' => '10', 'confirmations' => 1])]);
        $res = $this->client($t)->sandbox()->simulateDeposit('inv-1', ['amount' => '10', 'confirmations' => 1, 'txid' => 'tx-1']);

        self::assertSame('tx-1', $res['txid']);
        $call = $t->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.test/v1/sandbox/deposit', $call['url']);
        self::assertSame(
            ['invoice_id' => 'inv-1', 'amount' => '10', 'confirmations' => 1, 'txid' => 'tx-1'],
            json_decode($call['body'], true),
        );
        self::assertSame('test_pub', $call['headers']['X-Public-Id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $call['headers']['X-Signature']);
    }

    public function testSimulateDepositMinimalBody(): void
    {
        $t = new MockTransport([self::ok(['invoice_id' => 'inv-1', 'txid' => 'fresh', 'amount' => '10', 'confirmations' => 0])]);
        $this->client($t)->sandbox()->simulateDeposit('inv-1');

        self::assertSame(['invoice_id' => 'inv-1'], json_decode($t->calls[0]['body'], true));
    }

    public function testFaucet(): void
    {
        $t = new MockTransport([self::ok(['asset' => 'USDT', 'amount' => '100', 'journal_id' => 'j-1'])]);
        $res = $this->client($t)->sandbox()->faucet('USDT', '100', 'idem-1');

        self::assertSame('j-1', $res['journal_id']);
        self::assertSame('https://api.test/v1/sandbox/faucet', $t->calls[0]['url']);
        self::assertSame(
            ['asset' => 'USDT', 'amount' => '100', 'idempotency_key' => 'idem-1'],
            json_decode($t->calls[0]['body'], true),
        );
    }

    public function testFaucetWithoutIdempotencyKeyOmitsField(): void
    {
        $t = new MockTransport([self::ok(['asset' => 'USDT', 'amount' => '1', 'journal_id' => 'j-2'])]);
        $this->client($t)->sandbox()->faucet('USDT', '1');

        self::assertSame(['asset' => 'USDT', 'amount' => '1'], json_decode($t->calls[0]['body'], true));
    }

    public function testResetSendsEmptyObject(): void
    {
        $t = new MockTransport([self::ok(['invoices_cancelled' => 3, 'balances_zeroed' => 2])]);
        $res = $this->client($t)->sandbox()->reset();

        self::assertSame(3, $res['invoices_cancelled']);
        self::assertSame('POST', $t->calls[0]['method']);
        self::assertSame('https://api.test/v1/sandbox/reset', $t->calls[0]['url']);
        self::assertSame('{}', $t->calls[0]['body']);
    }

    public function testListWebhooksSignedGetEmptyBody(): void
    {
        $delivery = ['id' => 'd-1', 'event_type' => 'payment', 'url' => 'https://x', 'status' => 'ok', 'attempts' => 1];
        $t = new MockTransport([self::ok(['deliveries' => [$delivery]])]);
        $list = $this->client($t)->sandbox()->listWebhooks();

        self::assertSame('d-1', $list[0]['id']);
        $call = $t->calls[0];
        self::assertSame('GET', $call['method']);
        self::assertSame('https://api.test/v1/sandbox/webhooks', $call['url']);
        self::assertNull($call['body']);

        // Подпись — над пустым телом: "{ts}\nGET\n/v1/sandbox/webhooks\n".
        $ts = $call['headers']['X-Timestamp'];
        $expected = hash_hmac('sha256', $ts . "\nGET\n/v1/sandbox/webhooks\n", 'oblodai_test_sec');
        self::assertSame($expected, $call['headers']['X-Signature']);
        self::assertSame('test_pub', $call['headers']['X-Public-Id']);
    }

    public function testReplayWebhook(): void
    {
        $t = new MockTransport([self::ok(['delivery_id' => 'd-1', 'requeued' => true])]);
        $res = $this->client($t)->sandbox()->replayWebhook('d-1');

        self::assertTrue($res['requeued']);
        self::assertSame('https://api.test/v1/sandbox/webhooks/replay', $t->calls[0]['url']);
        self::assertSame(['delivery_id' => 'd-1'], json_decode($t->calls[0]['body'], true));
    }

    public function testIsTestKey(): void
    {
        self::assertTrue(Client::isTestKey('test_abc123'));
        self::assertTrue(Client::isTestKey('oblodai_test_abc123'));
        self::assertFalse(Client::isTestKey('oblodai_live_abc123'));
        self::assertFalse(Client::isTestKey('pub_1'));
        self::assertFalse(Client::isTestKey(''));
    }
}
