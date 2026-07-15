<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Сплит-платежи v1.1.0: правила и настройки.
 */
final class SplitsTest extends TestCase
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

    public function testSplitToAddress(): void
    {
        $client = $this->client(['rule_id' => 'r1', 'percent' => 12.5]);

        $res = $client->splits()->splitToAddress('T-addr', 'tron', 12.5, 'партнёр А');

        self::assertSame('r1', $res['rule_id']);
        self::assertSame('https://api.test/v1/split/rule', $this->t->calls[0]['url']);
        self::assertSame(
            ['address' => 'T-addr', 'network' => 'tron', 'percent' => 12.5, 'note' => 'партнёр А'],
            $this->body(),
        );
    }

    public function testSplitToMerchant(): void
    {
        $client = $this->client(['rule_id' => 'r2', 'percent' => 5.0]);

        $client->splits()->splitToMerchant('m-uuid', 5.0);

        $body = $this->body();
        self::assertSame('m-uuid', $body['merchant_id']);
        self::assertArrayNotHasKey('address', $body);
        self::assertArrayNotHasKey('note', $body);
    }

    public function testCreateRuleRawParams(): void
    {
        $client = $this->client(['rule_id' => 'r3', 'percent' => 1.0]);

        $client->splits()->createRule(['merchant_id' => 'm-uuid', 'percent' => 1.0, 'note' => 'n']);

        self::assertSame('https://api.test/v1/split/rule', $this->t->calls[0]['url']);
        self::assertSame('n', $this->body()['note']);
    }

    public function testListDeleteAndConfig(): void
    {
        $client = $this->client(['items' => []]);
        $client->splits()->listRules();
        self::assertSame('https://api.test/v1/split/rule/list', $this->t->calls[0]['url']);

        $client = $this->client(['deleted' => true]);
        $res = $client->splits()->deleteRule('r1');
        self::assertTrue($res['deleted']);
        self::assertSame('https://api.test/v1/split/rule/delete', $this->t->calls[0]['url']);
        self::assertSame(['rule_id' => 'r1'], $this->body());

        $client = $this->client(['refund_hold_hours' => 24]);
        $client->splits()->getConfig();
        self::assertSame('https://api.test/v1/split/config/get', $this->t->calls[0]['url']);

        $client = $this->client(['refund_hold_hours' => 48]);
        $client->splits()->setConfig(48);
        self::assertSame('https://api.test/v1/split/config/set', $this->t->calls[0]['url']);
        self::assertSame(['refund_hold_hours' => 48], $this->body());
    }
}
