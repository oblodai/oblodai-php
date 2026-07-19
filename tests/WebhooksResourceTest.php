<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Управление вебхуками: форма ответа `deliveries()` и регистрация эндпоинта.
 */
final class WebhooksResourceTest extends TestCase
{
    private function client(MockTransport $t): Client
    {
        return new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);
    }

    /** @param array<string,mixed> $result */
    private static function ok(array $result): Response
    {
        return new Response(200, (string) json_encode(['state' => 0, 'result' => $result]));
    }

    /**
     * Конверт `{deliveries: [...]}` разворачивается — как в sandbox()->listWebhooks() и как в
     * остальных SDK (ломающее изменение v1.2.0).
     */
    public function testDeliveriesReturnsList(): void
    {
        $delivery = ['id' => 'd-1', 'url' => 'https://x', 'event_type' => 'payment', 'status' => 'ok'];
        $t = new MockTransport([self::ok(['deliveries' => [$delivery]])]);

        $list = $this->client($t)->webhooks()->deliveries();

        self::assertCount(1, $list);
        self::assertSame('d-1', $list[0]['id']);
        self::assertArrayNotHasKey('deliveries', $list);

        $call = $t->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://api.test/v1/webhooks/deliveries', $call['url']);
        self::assertSame('{}', $call['body']);
    }

    /** Пустой журнал — пустой список, а не null и не конверт. */
    public function testDeliveriesEmpty(): void
    {
        $t = new MockTransport([self::ok(['deliveries' => []])]);
        self::assertSame([], $this->client($t)->webhooks()->deliveries());
    }

    /** Обе доставочные ручки SDK отдают одну и ту же форму. */
    public function testDeliveriesShapeMatchesSandboxListWebhooks(): void
    {
        $delivery = ['id' => 'd-9', 'event_type' => 'payout', 'status' => 'failed'];

        $mgmt = new MockTransport([self::ok(['deliveries' => [$delivery]])]);
        $sandbox = new MockTransport([self::ok(['deliveries' => [$delivery]])]);

        self::assertSame(
            $this->client($mgmt)->webhooks()->deliveries(),
            $this->client($sandbox)->sandbox()->listWebhooks(),
        );
    }

    /** register() — обычный подписанный POST с одним полем url; ответ отдаётся как есть. */
    public function testRegisterPostsUrl(): void
    {
        $t = new MockTransport([self::ok([
            'endpoint_id' => 'ep-1',
            'url' => 'https://shop.example/hook',
            'secret' => 'whsec',
        ])]);

        $res = $this->client($t)->webhooks()->register('https://shop.example/hook');

        // Секрет ЭНДПОИНТА (не секрет API-ключа) — им и проверяются входящие вебхуки.
        self::assertSame('whsec', $res['secret']);
        self::assertNotSame('sec', $res['secret']);
        self::assertSame('https://api.test/v1/webhooks', $t->calls[0]['url']);
        self::assertSame(['url' => 'https://shop.example/hook'], json_decode((string) $t->calls[0]['body'], true));
    }
}
