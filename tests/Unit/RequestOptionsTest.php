<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Core\RequestOptions;
use Oblodai\Core\Retry;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Exception\TransportException;
use Oblodai\Generated\Model\FaucetRequest;
use Oblodai\Generated\Signing;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Per-call options are explicit (spec §3 item 2): `idempotencyKey`, `timeout` in seconds,
 * `maxRetries`, `extraHeaders`, `requestId`; every call carries its own `X-Request-ID` (item 9).
 */
final class RequestOptionsTest extends TestCase
{
    private const CREDS = ['publicId' => 'pk', 'secret' => 's', 'baseUrl' => 'https://api.test'];

    private static function client(FakeHttpClient $fake, int $maxRetries = 2, int|float|null $timeout = null): Oblodai
    {
        return new Oblodai(
            ...self::CREDS,
            http: $fake,
            timeout: $timeout,
            retry: new Retry(maxRetries: $maxRetries, baseDelayMs: 1, maxDelayMs: 2),
            env: [],
        );
    }

    public function testTimeoutIsSecondsOnTheClientAndPerCall(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::sample('getBalance'), FakeHttpClient::sample('getBalance')]);
        $ob = self::client($fake, timeout: 12);

        $ob->account->getBalance();
        $ob->account->getBalance(new RequestOptions(timeout: 2.5));

        self::assertEqualsWithDelta(12.0, $fake->timeouts[0], 0.001);
        self::assertEqualsWithDelta(2.5, $fake->timeouts[1], 0.001);
    }

    public function testTheDefaultTimeoutIsThirtySeconds(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::sample('getBalance')]);
        self::client($fake)->account->getBalance();

        self::assertEqualsWithDelta(30.0, $fake->timeouts[0], 0.001);
    }

    public function testMaxRetriesOverridesThePolicyForOneCall(): void
    {
        $down = FakeHttpClient::error(503, ['code' => 'db.unavailable', 'retryable' => true, 'retry_after' => 0]);
        $fake = new FakeHttpClient([$down, $down, $down, $down, $down]);
        $ob = self::client($fake, maxRetries: 4);

        try {
            $ob->account->getBalance(new RequestOptions(maxRetries: 1));
            self::fail('expected an OblodaiException');
        } catch (OblodaiException $e) {
            self::assertSame('db.unavailable', $e->errorCode);
        }
        self::assertSame(2, $fake->count(), 'one attempt and one retry');

        $fake2 = new FakeHttpClient([$down]);

        try {
            self::client($fake2, maxRetries: 4)->account->getBalance(new RequestOptions(maxRetries: 0));
            self::fail('expected an OblodaiException');
        } catch (OblodaiException) {
        }
        self::assertSame(1, $fake2->count(), 'maxRetries: 0 is no retry at all');
    }

    public function testExtraHeadersRideOnThisCallOnly(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::sample('getBalance'), FakeHttpClient::sample('getBalance')]);
        $ob = self::client($fake);

        $ob->account->getBalance(new RequestOptions(extraHeaders: ['X-Trace' => 't-1']));
        $ob->account->getBalance();

        self::assertSame('t-1', $fake->header(0, 'X-Trace'));
        self::assertNull($fake->header(1, 'X-Trace'));
    }

    public function testEveryCallSendsItsOwnRequestIdTheSameOnEveryAttempt(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::error(503, ['code' => 'db.unavailable', 'retryable' => true, 'retry_after' => 0]),
            FakeHttpClient::sample('getBalance'),
            FakeHttpClient::sample('getBalance'),
        ]);
        $ob = self::client($fake);

        $ob->account->getBalance();
        $ob->account->getBalance();

        $first = (string) $fake->header(0, 'X-Request-ID');
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first);
        self::assertSame($first, $fake->header(1, 'X-Request-ID'), 'a retry is the same call');
        self::assertNotSame($first, $fake->header(2, 'X-Request-ID'), 'the next call is another');
    }

    public function testACallerRequestIdIsSentAndCannotBeForgedThroughHeaders(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::sample('getBalance'), FakeHttpClient::sample('getBalance')]);
        $ob = self::client($fake);

        $ob->account->getBalance(new RequestOptions(requestId: 'order-42-attempt'));
        $ob->account->getBalance(new RequestOptions(extraHeaders: ['x-request-id' => 'from-header']));

        self::assertSame('order-42-attempt', $fake->header(0, 'X-Request-ID'));
        self::assertSame('from-header', $fake->header(1, 'X-Request-ID'), 'a header spelling is honoured, once');
        $ids = array_filter(array_keys($fake->calls[1]->headers), static fn (string $n): bool => strtolower($n) === 'x-request-id');
        self::assertCount(1, $ids);
    }

    public function testAnUnsendableRequestIdIsRefusedBeforeTheNetwork(): void
    {
        $fake = new FakeHttpClient([]);

        try {
            self::client($fake)->account->getBalance(new RequestOptions(requestId: "a\nb"));
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('sdk.bad_header', $e->errorCode);
        }
        self::assertSame(0, $fake->count());
    }

    public function testAnErrorCarriesTheRequestIdOfItsCall(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::error(400, ['code' => 'payment.bad_amount', 'message' => 'bad', 'retryable' => false]),
            FakeHttpClient::error(400, ['code' => 'payment.bad_amount', 'message' => 'bad', 'retryable' => false], ['x-request-id' => 'srv-1']),
            FakeHttpClient::throws(TransportException::NETWORK, 'reset'),
        ]);
        $ob = self::client($fake, maxRetries: 0);

        foreach (['mine-1' => 'mine-1', 'mine-2' => 'srv-1', 'mine-3' => 'mine-3'] as $sent => $want) {
            try {
                $ob->payments->cancel(['uuid' => 'u'], new RequestOptions(requestId: $sent));
                self::fail('expected an OblodaiException');
            } catch (OblodaiException $e) {
                self::assertSame($want, $e->requestId);
                self::assertStringEndsWith('(request_id=' . $want . ')', $e->getMessage());
            }
        }
    }

    public function testAnIdempotencyKeyIsTheOneSentAndOnlyWhereTheGatewayDeduplicates(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::sample('createPayment')]);
        $ob = self::client($fake);

        $ob->payments->create(['amount' => '1', 'currency' => 'USDT'], new RequestOptions(idempotencyKey: 'k-1'));
        self::assertSame('k-1', $fake->header(0, Signing::HEADER_IDEMPOTENCY_KEY));

        $this->expectException(ConfigException::class);
        $ob->payments->cancel(['uuid' => 'u'], new RequestOptions(idempotencyKey: 'k-2'));
    }

    public function testNonsenseTimeoutsAndRetriesAreConfigErrors(): void
    {
        $fake = new FakeHttpClient([]);
        $ob = self::client($fake);
        foreach ([new RequestOptions(timeout: 0), new RequestOptions(timeout: -1), new RequestOptions(maxRetries: -1)] as $options) {
            try {
                $ob->account->getBalance($options);
                self::fail('expected a ConfigException');
            } catch (ConfigException $e) {
                self::assertSame('sdk.bad_config', $e->errorCode);
            }
        }
        self::assertSame(0, $fake->count());

        $this->expectException(ConfigException::class);
        new Oblodai(...self::CREDS, timeout: 0, env: []);
    }
    public function testTheFaucetTakesTheIdempotencyKeyInItsBody(): void
    {
        // Ruling 10: the faucet deduplicates by its own body field, not by the header.
        $fake = new FakeHttpClient([FakeHttpClient::sample('sandboxFaucet')]);
        self::client($fake)->sandbox->faucet(['asset' => 'USDT', 'amount' => '5'], new RequestOptions(idempotencyKey: 'tap-1'));

        self::assertSame('tap-1', $fake->body(0)['idempotency_key'] ?? null);
        self::assertNull($fake->header(0, Signing::HEADER_IDEMPOTENCY_KEY));
    }

    public function testTheFaucetKeyGivenTwiceIsAnErrorBeforeTheNetwork(): void
    {
        // The same rule in every SDK: the field and the option both naming the key is ambiguous.
        $fake = new FakeHttpClient([FakeHttpClient::sample('sandboxFaucet'), FakeHttpClient::sample('sandboxFaucet')]);
        $ob = self::client($fake);
        $both = [
            ['asset' => 'USDT', 'amount' => '5', 'idempotency_key' => 'own'],
            new FaucetRequest(amount: '5', asset: 'USDT', idempotency_key: 'own'),
        ];
        foreach ($both as $params) {
            try {
                $ob->sandbox->faucet($params, new RequestOptions(idempotencyKey: 'tap-2'));
                self::fail('expected a ConfigException');
            } catch (ConfigException $e) {
                self::assertSame('sdk.bad_config', $e->errorCode);
                self::assertSame('idempotency_key', $e->field);
            }
        }
        self::assertSame(0, $fake->count());

        // A model without its own key, or a body whose key is null, takes the option.
        $ob->sandbox->faucet(new FaucetRequest(amount: '5', asset: 'USDT'), new RequestOptions(idempotencyKey: 'tap-3'));
        self::assertSame('tap-3', $fake->body(0)['idempotency_key'] ?? null);
        $ob->sandbox->faucet(['asset' => 'USDT', 'amount' => '5', 'idempotency_key' => null], new RequestOptions(idempotencyKey: 'tap-4'));
        self::assertSame('tap-4', $fake->body(1)['idempotency_key'] ?? null);
    }
}
