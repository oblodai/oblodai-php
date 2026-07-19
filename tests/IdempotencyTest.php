<?php

declare(strict_types=1);

namespace Oblodai\Tests;

use Oblodai\Client;
use Oblodai\Exception\ConnectionException;
use Oblodai\Http\Response;
use Oblodai\Http\Transport;
use Oblodai\Signing;
use PHPUnit\Framework\TestCase;

/**
 * Идемпотентность v1.1.0: создающие вызовы уходят с заголовком Idempotency-Key,
 * который генерируется ОДИН раз до цикла повторов и одинаков во всех попытках.
 * order_id больше НЕ подставляется автоматически и передаётся как есть.
 */
final class IdempotencyTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @return array<string,mixed> */
    private function decodeBody(?string $body): array
    {
        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testPaymentCreateSendsIdempotencyKeyHeaderAndNoAutoOrderId(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => ['uuid' => 'p1']]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD']);

        $h = $t->calls[0]['headers'];
        self::assertArrayHasKey('Idempotency-Key', $h);
        self::assertMatchesRegularExpression(self::UUID_V4, $h['Idempotency-Key']);

        // Ломающее изменение: order_id НЕ подставляется автоматически.
        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertArrayNotHasKey('order_id', $body);
    }

    public function testPaymentCreateKeepsCallerOrderIdVerbatim(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD', 'order_id' => 'mine-1']);

        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertSame('mine-1', $body['order_id']);
    }

    public function testSameIdempotencyKeyAcrossRetries(): void
    {
        // Первая попытка — сетевой сбой, вторая — 200. Обе должны нести ОДИН Idempotency-Key.
        $t = new FlakyTransport(
            new Response(200, json_encode(['state' => 0, 'result' => ['uuid' => 'p1']])),
            1,
        );
        $client = new Client('pub', 'sec', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => 3, 'initial_delay_ms' => 1, 'max_delay_ms' => 2],
        ]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD']);

        self::assertCount(2, $t->calls);
        $first = $t->calls[0]['headers']['Idempotency-Key'];
        $second = $t->calls[1]['headers']['Idempotency-Key'];
        self::assertNotSame('', $first);
        self::assertSame($first, $second);
        // И тела попыток идентичны (нет никакой пере-генерации полей).
        self::assertSame($t->calls[0]['body'], $t->calls[1]['body']);
    }

    public function testCallerIdempotencyKeyGoesToHeaderNotBody(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD', 'idempotency_key' => 'my-key-1']);

        self::assertSame('my-key-1', $t->calls[0]['headers']['Idempotency-Key']);
        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertArrayNotHasKey('idempotency_key', $body);
    }

    public function testIdempotencyKeyIsNotPartOfSignature(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payments()->create(['amount' => '10', 'currency' => 'USD', 'order_id' => 'o1']);

        $call = $t->calls[0];
        // Подпись считается ТОЛЬКО от ts/метода/пути/тела — заголовок в неё не входит.
        [, $expected] = Signing::signRequest('sec', 'POST', '/v1/payment', (string) $call['body'], $call['headers']['X-Timestamp']);
        self::assertSame($expected, $call['headers']['X-Signature']);
    }

    public function testTransferToPersonalSendsHeaderAndNoAutoOrderId(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->account()->transferToPersonal(['amount' => '5', 'currency' => 'USDT']);

        self::assertArrayHasKey('Idempotency-Key', $t->calls[0]['headers']);
        $body = $this->decodeBody($t->calls[0]['body']);
        self::assertArrayNotHasKey('order_id', $body);
    }

    public function testPayoutAndRefundSendHeader(): void
    {
        $t = new MockTransport([new Response(200, json_encode(['state' => 0, 'result' => []]))]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payouts()->create(['amount' => '5', 'currency' => 'USDT', 'address' => 'T', 'order_id' => 'w-1']);
        $client->payments()->refund(['uuid' => 'p1']);

        self::assertArrayHasKey('Idempotency-Key', $t->calls[0]['headers']);
        self::assertArrayHasKey('Idempotency-Key', $t->calls[1]['headers']);
    }

    /**
     * v1.2.0: резервирующие баланс вызовы больше НЕ идут без ключа. Потерянный ответ на них
     * означал вторую профинансированную ссылку / второй резерв после авто-ретрая.
     */
    public function testFundReservingEndpointsSendIdempotencyKey(): void
    {
        $t = new MockTransport([
            new Response(200, json_encode(['state' => 0, 'result' => []])),
            new Response(200, json_encode(['state' => 0, 'result' => []])),
            new Response(200, json_encode(['state' => 0, 'result' => []])),
        ]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payoutLinks()->create(['currency' => 'BTC', 'network' => 'bitcoin', 'amount' => '0.001', 'expires_in_hours' => 24]);
        $client->payoutLinks()->createBatch([['currency' => 'BTC', 'network' => 'bitcoin', 'amount' => '0.001']]);
        $client->wallets()->blockedAddressRefund('w1', 'T-addr');

        foreach ([0, 1, 2] as $i) {
            $h = $t->calls[$i]['headers'];
            self::assertArrayHasKey('Idempotency-Key', $h, "call #$i");
            self::assertMatchesRegularExpression(self::UUID_V4, $h['Idempotency-Key'], "call #$i");
            // Ключ уходит только заголовком — тело им не «пачкается» (иначе сломалась бы подпись/дедуп).
            self::assertArrayNotHasKey('idempotency_key', $this->decodeBody($t->calls[$i]['body']), "call #$i");
        }
    }

    public function testFundReservingEndpointsAcceptExplicitKey(): void
    {
        $t = new MockTransport([
            new Response(200, json_encode(['state' => 0, 'result' => []])),
            new Response(200, json_encode(['state' => 0, 'result' => []])),
            new Response(200, json_encode(['state' => 0, 'result' => []])),
        ]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        $client->payoutLinks()->create(['currency' => 'BTC', 'network' => 'bitcoin', 'amount' => '0.001', 'idempotency_key' => 'mine-link']);
        $client->payoutLinks()->createBatch([['currency' => 'BTC', 'amount' => '0.001']], 'mine-batch');
        $client->wallets()->blockedAddressRefund('w1', 'T-addr', 'mine-refund');

        self::assertSame('mine-link', $t->calls[0]['headers']['Idempotency-Key']);
        self::assertSame('mine-batch', $t->calls[1]['headers']['Idempotency-Key']);
        self::assertSame('mine-refund', $t->calls[2]['headers']['Idempotency-Key']);
    }

    /**
     * Суть защиты: ключ генерируется ОДИН раз до цикла повторов. Если бы он менялся между
     * попытками, бэкенд принял бы ретрай за новый запрос и зарезервировал бы баланс дважды.
     */
    public function testPayoutLinkKeyIsStableAcrossRetries(): void
    {
        $t = new FlakyTransport(new Response(200, json_encode(['state' => 0, 'result' => ['link_id' => 'l1']])), 1);
        $client = new Client('pub', 'sec', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => 3, 'initial_delay_ms' => 1, 'max_delay_ms' => 2],
        ]);

        $client->payoutLinks()->create(['currency' => 'BTC', 'network' => 'bitcoin', 'amount' => '0.001']);

        self::assertCount(2, $t->calls);
        self::assertSame($t->calls[0]['headers']['Idempotency-Key'], $t->calls[1]['headers']['Idempotency-Key']);
        self::assertSame($t->calls[0]['body'], $t->calls[1]['body']);
    }

    /**
     * Сервер (core 8dffa7b) дедуплицирует /v1/payout/link* сам, поэтому авто-ретрай на них
     * ОТКЛЮЧАТЬ НЕЛЬЗЯ — это была бы деградация: 503/сетевой сбой остался бы неповторённым,
     * хотя повтор безопасен. Проверяем, что ретрай работает и что ключ при этом НЕ меняется.
     */
    public function testPayoutLinkRetriesOn503WithUnchangedKey(): void
    {
        $t = new MockTransport([
            new Response(503, json_encode(['state' => 1, 'error' => ['code' => 'idempotency.unavailable', 'message' => 'store down']])),
            new Response(200, json_encode(['state' => 0, 'result' => ['link_id' => 'l1']])),
        ]);
        $client = new Client('pub', 'sec', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => 3, 'initial_delay_ms' => 1, 'max_delay_ms' => 2],
        ]);

        $result = $client->payoutLinks()->create(['currency' => 'BTC', 'network' => 'bitcoin', 'amount' => '0.001']);

        self::assertSame(['link_id' => 'l1'], $result);
        self::assertCount(2, $t->calls, '503 idempotency.unavailable должен быть повторён');
        self::assertSame(
            $t->calls[0]['headers']['Idempotency-Key'],
            $t->calls[1]['headers']['Idempotency-Key'],
            'ключ обязан пережить внутренний ретрай без изменений',
        );
    }

    /**
     * Классификация ошибок идемпотентности: 4xx терминальны (повтор их не разрешит и лишь
     * упрётся в ту же ошибку), 503 — временная. Отдельно важен переезд дубля reference
     * с 500 на 409: раньше SDK его ретраил, теперь — нет.
     *
     * @dataProvider terminalIdempotencyErrors
     */
    public function testIdempotencyClientErrorsAreTerminalAndNotRetried(int $status, string $code): void
    {
        $t = new MockTransport([
            new Response($status, json_encode(['state' => 1, 'error' => ['code' => $code, 'message' => 'nope']])),
            new Response(200, json_encode(['state' => 0, 'result' => ['link_id' => 'must-not-be-reached']])),
        ]);
        $client = new Client('pub', 'sec', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => 3, 'initial_delay_ms' => 1, 'max_delay_ms' => 2],
        ]);

        try {
            $client->payoutLinks()->create(['currency' => 'BTC', 'network' => 'bitcoin', 'amount' => '0.001']);
            self::fail("ожидалась ApiException {$code}");
        } catch (\Oblodai\Exception\ApiException $e) {
            self::assertSame($code, $e->getErrorCode());
            self::assertSame($status, $e->getStatusCode());
            self::assertFalse($e->isRetriable(), "{$code} не должен считаться ретраибельным");
        }

        self::assertCount(1, $t->calls, "{$code} не должен повторяться");
    }

    /** @return array<string,array{int,string}> */
    public static function terminalIdempotencyErrors(): array
    {
        return [
            'bad key' => [400, 'idempotency.bad_key'],
            'key reused with another body' => [400, 'idempotency.key_reused'],
            'first request still running' => [409, 'idempotency.in_progress'],
            'duplicate reference (был 500)' => [409, 'payoutlink.duplicate_reference'],
        ];
    }

    public function testIdempotencyUnavailableIsClassifiedRetriable(): void
    {
        $t = new MockTransport([
            new Response(503, json_encode(['state' => 1, 'error' => ['code' => 'idempotency.unavailable', 'message' => 'store down']])),
        ]);
        $client = new Client('pub', 'sec', ['base_url' => 'https://api.test', 'transport' => $t, 'retry' => false]);

        try {
            $client->payoutLinks()->createBatch([['currency' => 'BTC', 'amount' => '0.001']]);
            self::fail('ожидалась ApiException idempotency.unavailable');
        } catch (\Oblodai\Exception\ApiException $e) {
            self::assertSame('idempotency.unavailable', $e->getErrorCode());
            self::assertTrue($e->isRetriable(), 'недоступность стора временна — повтор с тем же ключом безопасен');
        }
    }

    public function testBlockedAddressRefundKeyIsStableAcrossRetries(): void
    {
        $t = new FlakyTransport(new Response(200, json_encode(['state' => 0, 'result' => ['uuid' => 'p1']])), 1);
        $client = new Client('pub', 'sec', [
            'base_url' => 'https://api.test',
            'transport' => $t,
            'retry' => ['max_attempts' => 3, 'initial_delay_ms' => 1, 'max_delay_ms' => 2],
        ]);

        $client->wallets()->blockedAddressRefund('w1', 'T-addr');

        self::assertCount(2, $t->calls);
        self::assertSame($t->calls[0]['headers']['Idempotency-Key'], $t->calls[1]['headers']['Idempotency-Key']);
    }
}

/**
 * Транспорт, который первые $failFirst раз бросает ConnectionException, затем отдаёт ответ.
 * Запоминает ВСЕ попытки (включая упавшие), чтобы сверить одинаковость заголовков/тел.
 */
final class FlakyTransport implements Transport
{
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];

    private Response $ok;

    private int $failFirst;

    public function __construct(Response $ok, int $failFirst)
    {
        $this->ok = $ok;
        $this->failFirst = $failFirst;
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if (count($this->calls) <= $this->failFirst) {
            throw new ConnectionException('boom');
        }

        return $this->ok;
    }
}
