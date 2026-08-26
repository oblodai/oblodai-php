<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Fiber;
use Oblodai\Core\Clock;
use Oblodai\Core\RequestOptions;
use Oblodai\Core\Retry;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\TransportException;
use Oblodai\Http\HttpClient;
use Oblodai\Http\HttpRequest;
use Oblodai\Http\HttpResponse;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * The two things a shared client gets wrong under concurrency: the clock offset every call signs
 * with, and the idempotency key a caller hands it.
 */
final class ClockSkewConcurrencyTest extends TestCase
{
    private const CREDS = ['publicId' => 'pk', 'secret' => 's', 'baseUrl' => 'https://api.test'];

    private static function fastRetry(int $maxRetries = 2): Retry
    {
        return new Retry(maxRetries: $maxRetries, baseDelayMs: 1, maxDelayMs: 2);
    }

    /**
     * A host whose clock is an hour off gets ONE correction, shared by every call in flight.
     *
     * Fibers make the interleaving real: each call suspends inside the HTTP client — exactly where a
     * real request waits on its socket — so all of them have signed with the old offset before any
     * of them sees the server's `Date`. With a blind rollback ("put back what I found"), the last
     * fiber to give up would undo the correction the others are relying on.
     */
    public function testConcurrentCallsConvergeOnOneClockCorrection(): void
    {
        $skewSeconds = 3600;
        $serverNow = 1_800_000_000;
        $localNow = $serverNow - $skewSeconds;
        $clock = new Clock(static fn (): int => $localNow);

        $http = new class ($serverNow) implements HttpClient {
            public int $rejected = 0;
            public int $accepted = 0;

            public function __construct(private readonly int $serverNow)
            {
            }

            public function send(HttpRequest $request, float $timeoutSeconds): HttpResponse
            {
                // Suspend where a real client waits on the socket, so the calls interleave.
                if (Fiber::getCurrent() !== null) {
                    Fiber::suspend();
                }
                $signedAt = (int) ($request->headers['X-Timestamp'] ?? '0');
                $date = gmdate('D, d M Y H:i:s', $this->serverNow) . ' GMT';
                if (abs($this->serverNow - $signedAt) > 300) {
                    ++$this->rejected;

                    return new HttpResponse(
                        401,
                        ['content-type' => 'application/json', 'date' => $date],
                        (string) json_encode(['error' => ['code' => 'merchant.bad_signature', 'message' => 'skew']])
                    );
                }
                ++$this->accepted;

                return new HttpResponse(
                    200,
                    ['content-type' => 'application/json', 'date' => $date],
                    (string) json_encode(['state' => 0, 'result' => ['uuid' => 'p-' . $signedAt]])
                );
            }
        };

        $ob = new Oblodai(...self::CREDS, http: $http, clock: $clock, retry: self::fastRetry(0), env: []);

        $fibers = [];
        for ($i = 0; $i < 8; ++$i) {
            $fiber = new Fiber(static function () use ($ob, $i): string {
                return $ob->payments->create(['amount' => '1', 'currency' => 'USDT', 'order_id' => 'o' . $i])->uuid;
            });
            $fiber->start();
            $fibers[] = $fiber;
        }
        while (array_filter($fibers, static fn (Fiber $f): bool => !$f->isTerminated()) !== []) {
            foreach ($fibers as $fiber) {
                if (!$fiber->isTerminated() && $fiber->isSuspended()) {
                    $fiber->resume();
                }
            }
        }

        foreach ($fibers as $fiber) {
            self::assertSame('p-' . $serverNow, $fiber->getReturn(), 'every call must succeed after one correction');
        }
        self::assertSame(8, $http->accepted);
        self::assertSame(8, $http->rejected, 'each call is rejected exactly once, then re-signed');
        self::assertSame($skewSeconds, $clock->offset(), 'the correction survives');
    }

    /** A call that carries a caller key on a route the core does not deduplicate is refused. */
    public function testACallerKeyOnANonIdempotentRouteIsStillRefused(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['valid' => true])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $this->expectException(ConfigException::class);
        $ob->payouts->validate(
            ['amount' => '1', 'currency' => 'USDT', 'address' => 'T'],
            new RequestOptions(idempotencyKey: 'k')
        );
    }

    /** A key the SDK itself rejects is the caller's mistake, before any request — a config error. */
    public function testAnUnusableIdempotencyKeyIsAConfigErrorNotA400(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['uuid' => 'p'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        foreach (['', ' has space ', str_repeat('k', 256), "tab\tkey"] as $key) {
            try {
                $ob->payments->create(
                    ['amount' => '1', 'currency' => 'USDT'],
                    new RequestOptions(idempotencyKey: $key)
                );
                self::fail(sprintf('expected a ConfigException for %s', json_encode($key)));
            } catch (ConfigException $e) {
                self::assertSame('sdk.bad_idempotency_key', $e->errorCode);
                self::assertSame(0, $e->httpStatus, 'nothing was sent, so there is no HTTP status');
            }
        }
        self::assertSame(0, $fake->count());
    }

    public function testATransportFailureStillCarriesItsCode(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::throws(TransportException::NETWORK, 'socket closed')]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: self::fastRetry(0), env: []);

        $this->expectException(TransportException::class);
        $ob->payments->create(['amount' => '1', 'currency' => 'USDT']);
    }

    public function testPerCallHeadersRideOnTopOfTheClientsOwn(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['uuid' => 'p'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, headers: ['X-Shop' => 'one', 'X-Tenant' => 't'], env: []);

        $ob->payments->create(
            ['amount' => '1', 'currency' => 'USDT'],
            new RequestOptions(headers: ['x-shop' => 'two'])
        );

        self::assertSame('two', $fake->header(0, 'X-Shop'), 'the per-call header wins');
        self::assertSame('t', $fake->header(0, 'X-Tenant'), 'the client header survives');
        $shopHeaders = array_filter(
            array_keys($fake->calls[0]->headers),
            static fn (string $name): bool => strtolower($name) === 'x-shop'
        );
        self::assertCount(1, $shopHeaders, 'the shadowed spelling must not travel beside it');
    }

    public function testAPerCallHeaderCannotOverrideASignedOne(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['uuid' => 'p'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $ob->payments->create(
            ['amount' => '1', 'currency' => 'USDT'],
            new RequestOptions(headers: ['Idempotency-Key' => 'forged', 'X-Timestamp' => '1'])
        );

        self::assertNotSame('forged', $fake->header(0, 'Idempotency-Key'));
        self::assertNotSame('1', $fake->header(0, 'X-Timestamp'));
    }

    public function testABadCallerHeaderHasItsOwnCode(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['uuid' => 'p'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        try {
            $ob->payments->create(['amount' => '1', 'currency' => 'USDT'], new RequestOptions(headers: ['X-Shop' => "a\nb"]));
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('sdk.bad_header', $e->errorCode);
        }
    }

    /**
     * A body past the ceiling is its own, non-retryable failure: repeating the request would
     * produce the same oversized body.
     */
    public function testAnOversizedResponseHasItsOwnCodeAndIsNotRetried(): void
    {
        $attempts = 0;
        $http = new class ($attempts) implements HttpClient {
            public function __construct(public int &$attempts)
            {
            }

            public function send(HttpRequest $request, float $timeoutSeconds): HttpResponse
            {
                ++$this->attempts;

                throw new TransportException(
                    TransportException::RESPONSE_TOO_LARGE,
                    sprintf('response body exceeds the %d-byte ceiling', $request->maxResponseBytes)
                );
            }
        };
        $ob = new Oblodai(...self::CREDS, http: $http, retry: self::fastRetry(3), env: []);

        try {
            $ob->catalog->currencies();
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame('sdk.response_too_large', $e->errorCode);
            self::assertFalse($e->retryable);
        }
        self::assertSame(1, $attempts, 'a body too large will be too large again');
    }
}
