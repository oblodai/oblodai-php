<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Core\RequestOptions;
use Oblodai\Core\Retry;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Exception\TransportException;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Ports the double-spend / clock-skew / redirect / deadline half of test/unit/money-paths.test.ts.
 * The rest of the transport contract lives in TransportTest.php.
 */
final class TransportSafetyTest extends TestCase
{
    private const CREDS = [
        'publicId' => 'pk',
        'secret' => 's',
        'payoutPublicId' => 'wk',
        'payoutSecret' => 's2',
        'baseUrl' => 'https://api.test',
    ];

    private static function fastRetry(int $maxRetries = 2): Retry
    {
        return new Retry(maxRetries: $maxRetries, baseDelayMs: 1, maxDelayMs: 2);
    }

    public function testRejectsACallerIdempotencyKeyOnARouteTheCoreDoesNotDeduplicate(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok([])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        for ($i = 0; $i < 2; ++$i) {
            try {
                $ob->payouts->approve('p1', new RequestOptions(idempotencyKey: 'k1'));
                self::fail('expected a ConfigException');
            } catch (ConfigException $e) {
                self::assertSame(ConfigException::IDEMPOTENCY_UNSUPPORTED, $e->errorCode);
            }
        }
        self::assertSame(0, $fake->count());
    }

    public function testNeverResendsAnUnsafeWriteAfterAProxy503WithoutAnEnvelope(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::raw(503, '<html>upstream error</html>', ['content-type' => 'text/html'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: self::fastRetry(), env: []);

        try {
            $ob->payouts->approve('p1');
            self::fail('expected an OblodaiException');
        } catch (OblodaiException $e) {
            self::assertSame(503, $e->httpStatus);
            self::assertTrue($e->synthetic);
            self::assertTrue($e->retryable);
        }
        self::assertSame(1, $fake->count());
    }

    public function testRetriesAReadRouteAfterAProxy502Or504AndHonoursTheRetryAfterHeader(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::raw(502, '<html>upstream error</html>', ['content-type' => 'text/html']),
            FakeHttpClient::raw(504, '<html>upstream error</html>', ['content-type' => 'text/html', 'retry-after' => '0']),
            FakeHttpClient::ok(['balance' => ['merchant' => []]]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: self::fastRetry(), env: []);

        $ob->account->balance();
        self::assertSame(3, $fake->count());

        $fake2 = new FakeHttpClient([
            FakeHttpClient::raw(429, '<html>upstream error</html>', ['content-type' => 'text/html', 'retry-after' => '120']),
        ]);
        $ob2 = new Oblodai(...self::CREDS, http: $fake2, retry: new Retry(maxRetries: 0), env: []);

        try {
            $ob2->account->balance();
            self::fail('expected an OblodaiException');
        } catch (OblodaiException $e) {
            self::assertSame(120, $e->retryAfter);
        }
    }

    public function testRetriesAnEnvelopedRetryableErrorOnAnUnsafeWrite(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::error(409, ['code' => 'payout.funds_maturing', 'retryable' => true, 'retry_after' => 0]),
            FakeHttpClient::ok(['uuid' => 'p', 'status' => 'pending', 'fee_bearer' => 'gateway']),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: self::fastRetry(), env: []);

        $ob->payouts->approve('p1');

        self::assertSame(2, $fake->count());
    }

    public function testIgnoresTheDateHeaderOnA401ThatIsNotASignatureFailure(): void
    {
        $dateFar = gmdate('D, d M Y H:i:s', time() + 4000) . ' GMT';
        $fake = new FakeHttpClient([
            FakeHttpClient::error(401, ['code' => 'auth.ip_not_allowed', 'retryable' => false], ['date' => $dateFar]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: new Retry(maxRetries: 0), env: []);

        try {
            $ob->account->balance();
            self::fail('expected an OblodaiException');
        } catch (OblodaiException $e) {
            self::assertSame('auth.ip_not_allowed', $e->errorCode);
        }
        self::assertSame(1, $fake->count());
    }

    public function testRevertsTheCorrectionWhenTheResignedAttemptIsStillRejected(): void
    {
        $dateFar = gmdate('D, d M Y H:i:s', time() + 4000) . ' GMT';
        $fake = new FakeHttpClient([
            FakeHttpClient::error(401, ['code' => 'merchant.bad_signature', 'retryable' => false], ['date' => $dateFar]),
            FakeHttpClient::error(401, ['code' => 'merchant.bad_signature', 'retryable' => false], ['date' => $dateFar]),
            FakeHttpClient::ok(['balance' => ['merchant' => []]]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: new Retry(maxRetries: 0), env: []);

        try {
            $ob->account->balance();
            self::fail('expected an OblodaiException');
        } catch (OblodaiException $e) {
            self::assertSame('merchant.bad_signature', $e->errorCode);
        }

        // One bad Date cannot wedge the client: the next call signs with local time again.
        $ob->account->balance();
        $ts = (int) $fake->header(2, 'X-Timestamp');
        self::assertLessThan(5, abs($ts - time()));
    }

    public function testStopsRetryingWhenTheOverallDeadlineWouldBeExceeded(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::error(503, ['code' => 'db.unavailable', 'retryable' => true, 'retry_after' => 2]),
            FakeHttpClient::ok([]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, deadlineMs: 100, env: []);

        try {
            $ob->account->balance();
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportException::DEADLINE, $e->errorCode);
        }
        self::assertSame(1, $fake->count());
    }

    public function testNamesTheRedirectTargetInsteadOfABareEnvelopeError(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::raw(301, '', ['location' => 'https://www.api.test/v1/balance'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: new Retry(maxRetries: 0), env: []);

        try {
            $ob->account->balance();
            self::fail('expected an OblodaiException');
        } catch (OblodaiException $e) {
            self::assertSame(301, $e->httpStatus);
            self::assertMatchesRegularExpression('/redirect.*www\.api\.test/', $e->getMessage());
        }
    }
}
