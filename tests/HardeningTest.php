<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Робастность повторов (v1.0.2):
 *  - отрицательный Retry-After не роняет клиент через ValueError из usleep();
 *  - пробельный/невалидный order_id нормализуется (авто-идемпотентность);
 *  - реальный order_id вызывающего сохраняется и стабилен между попытками.
 */
final class HardeningTest extends TestCase
{
    /** @return array<string,mixed> */
    private function decodeBody(?string $body): array
    {
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testNegativeRetryAfterDoesNotThrowValueError(): void
    {
        // Первый ответ — 429 с Retry-After: -5 (некорректный), второй — 200.
        // Кламп снизу нулём должен дать usleep(0), а не ValueError.
        $t = new MockTransport([
            new Response(429, json_encode(['state' => 1, 'message' => 'rate limit exceeded']), -5.0),
            new Response(200, json_encode(['state' => 0, 'result' => ['uuid' => 'p1']])),
        ]);
        $client = new Client('pub', 'sec', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => 3, 'initial_delay_ms' => 1, 'max_delay_ms' => 2],
        ]);

        // Не должно быть ValueError; повтор доходит до успешного ответа.
        $result = $client->payments()->create(['amount' => '10', 'currency' => 'USD']);

        self::assertSame(['uuid' => 'p1'], $result);
        self::assertCount(2, $t->calls);
    }

    public function testWhitespaceOrderIdIsNormalized(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD', 'order_id' => '   ']);

        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertArrayHasKey('order_id', $body);
        self::assertIsString($body['order_id']);
        self::assertNotSame('   ', $body['order_id'], 'пробельный order_id должен быть заменён');
        self::assertNotSame('', trim($body['order_id']));
        self::assertStringStartsWith('idem-', $body['order_id']);
    }

    public function testRealOrderIdPreservedAndStableAcrossRetries(): void
    {
        // Первая попытка — сетевой сбой, вторая — 200. order_id вызывающего сохраняется и одинаков.
        $t = new FlakyTransport(
            new Response(200, json_encode(['state' => 0, 'result' => ['uuid' => 'p1']])),
            1,
        );
        $client = new Client('pub', 'sec', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => 3, 'initial_delay_ms' => 1, 'max_delay_ms' => 2],
        ]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD', 'order_id' => 'mine-42']);

        self::assertCount(2, $t->calls);
        $first = $this->decodeBody($t->calls[0]['body']);
        $second = $this->decodeBody($t->calls[1]['body']);
        self::assertSame('mine-42', $first['order_id']);
        self::assertSame('mine-42', $second['order_id']);
    }
}
