<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Contract\Model\Currencies;
use Oblodai\Core\RequestOptions;
use Oblodai\Core\Retry;
use Oblodai\Exception\AuthenticationException;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\IdempotencyConflictException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Exception\RateLimitException;
use Oblodai\Exception\TransportException;
use Oblodai\Exception\ValidationException;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Ports test/unit/transport.test.ts against a fake HTTP stack: signing, idempotency, retries,
 * error classification, credential selection, request construction. The double-spend/proxy/skew
 * half (mirroring money-paths.test.ts) lives in TransportSafetyTest.php.
 */
final class TransportTest extends TestCase
{
    private const CREDS = ['publicId' => 'pk_test_1', 'secret' => 'secret-1', 'baseUrl' => 'https://api.test'];

    private static function fastRetry(int $maxRetries = 2): Retry
    {
        return new Retry(maxRetries: $maxRetries, baseDelayMs: 1, maxDelayMs: 2);
    }

    public function testSignsPathAndQueryOnGetAndSendsNoBody(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::ok(['items' => [], 'paginate' => ['total' => 0, 'per_page' => 10, 'offset' => 0, 'has_pages' => false]]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $ob->sandbox->webhooks(['limit' => 10, 'offset' => 0])->items();

        self::assertSame('https://api.test/v1/sandbox/webhooks?limit=10&offset=0', $fake->calls[0]->url);
        self::assertNull($fake->calls[0]->body);
        self::assertSame('pk_test_1', $fake->header(0, 'X-Public-Id'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $fake->header(0, 'X-Signature'));
    }

    public function testGeneratesOneIdempotencyKeyPerCreateCallAndReusesItAcrossRetries(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::error(503, ['code' => 'db.unavailable', 'message' => 'down', 'retryable' => true]),
            FakeHttpClient::ok(['uuid' => 'u', 'status' => 'created']),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: self::fastRetry(), env: []);

        $ob->payments->create(['amount' => '1', 'currency' => 'USDT']);

        self::assertSame(2, $fake->count());
        $key = $fake->header(0, 'Idempotency-Key');
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $key);
        self::assertSame($key, $fake->header(1, 'Idempotency-Key'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $fake->header(1, 'X-Signature'));
    }

    public function testHonoursACallerSuppliedIdempotencyKeyAndDoesNotAddOneToReadRoutes(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::ok(['uuid' => 'u', 'status' => 'pending', 'fee_bearer' => 'gateway']),
            FakeHttpClient::ok(['uuid' => 'u', 'status' => 'created']),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $ob->payouts->create(
            ['amount' => '1', 'currency' => 'USDT', 'address' => 'T', 'order_id' => 'o'],
            new RequestOptions(idempotencyKey: 'my-key-1')
        );
        $ob->payments->info('u');

        self::assertSame('my-key-1', $fake->header(0, 'Idempotency-Key'));
        self::assertNull($fake->header(1, 'Idempotency-Key'));
    }

    public function testDoesNotRetryANonRetryableErrorEvenOnA5xx(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::error(500, ['code' => 'internal', 'retryable' => false])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        try {
            $ob->account->balance();
            self::fail('expected an OblodaiException');
        } catch (OblodaiException $e) {
            self::assertSame('internal', $e->errorCode);
            self::assertSame(500, $e->httpStatus);
            self::assertFalse($e->retryable);
        }
        self::assertSame(1, $fake->count());
    }

    public function testRetriesARetryableErrorAndHonoursRetryAfterThenSurfacesItAfterTheBudget(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::error(429, ['code' => 'request.rate_limited', 'retryable' => true, 'retry_after' => 0], ['retry-after' => '0']),
            FakeHttpClient::error(429, ['code' => 'request.rate_limited', 'retryable' => true, 'retry_after' => 0]),
            FakeHttpClient::error(429, ['code' => 'request.rate_limited', 'retryable' => true, 'retry_after' => 0]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: self::fastRetry(), env: []);

        try {
            $ob->account->balance();
            self::fail('expected a RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(0, $e->retryAfter);
        }
        self::assertSame(3, $fake->count()); // 1 + maxRetries(2)
    }

    public function testRetriesATransportFailureOnlyWhenTheRequestIsSafeToRepeat(): void
    {
        // Read route -> retried.
        $fake = new FakeHttpClient([FakeHttpClient::throws(), FakeHttpClient::ok(['balance' => ['merchant' => []]])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: self::fastRetry(), env: []);
        $ob->account->balance();
        self::assertSame(2, $fake->count());

        // Unkeyed write -> not retried.
        $fake2 = new FakeHttpClient([FakeHttpClient::throws(), FakeHttpClient::ok([])]);
        $ob2 = new Oblodai(...self::CREDS, http: $fake2, retry: self::fastRetry(), env: []);

        try {
            $ob2->settings->setAccuracy(['enabled' => true]);
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportException::NETWORK, $e->errorCode);
        }
        self::assertSame(1, $fake2->count());

        // Keyed create -> retried.
        $fake3 = new FakeHttpClient([FakeHttpClient::throws(), FakeHttpClient::ok(['uuid' => 'u', 'status' => 'created'])]);
        $ob3 = new Oblodai(...self::CREDS, http: $fake3, retry: self::fastRetry(), env: []);
        $ob3->payments->create(['amount' => '1', 'currency' => 'USDT']);
        self::assertSame(2, $fake3->count());
    }

    public function testClassifiesTheErrorEnvelopeIntoTheRightSubclassAndKeepsRequestIdAndField(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::error(400, [
                'code' => 'payment.below_minimum',
                'message' => 'too small',
                'field' => 'amount',
                'retryable' => false,
                'request_id' => 'rq-1',
            ]),
            FakeHttpClient::error(401, ['code' => 'merchant.bad_signature', 'message' => 'bad', 'retryable' => false]),
            FakeHttpClient::error(409, ['code' => 'idempotency.key_reused', 'message' => 'reused', 'retryable' => false]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: new Retry(maxRetries: 0), env: []);

        try {
            $ob->payments->create(['amount' => '0', 'currency' => 'USDT']);
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('payment.below_minimum', $e->errorCode);
            self::assertSame('amount', $e->field);
            self::assertSame('rq-1', $e->requestId);
            self::assertSame('payment', $e->family());
        }

        try {
            $ob->account->balance();
            self::fail('expected an AuthenticationException');
        } catch (AuthenticationException) {
        }

        try {
            $ob->payments->create(['amount' => '1', 'currency' => 'USDT']);
            self::fail('expected an IdempotencyConflictException');
        } catch (IdempotencyConflictException) {
        }
    }

    public function testResignsOnceWithTheServerClockWhenA401RevealsSkew(): void
    {
        $serverNow = time() + 3600;
        $fake = new FakeHttpClient([
            FakeHttpClient::error(
                401,
                ['code' => 'merchant.bad_signature', 'retryable' => false],
                ['date' => gmdate('D, d M Y H:i:s', $serverNow) . ' GMT']
            ),
            FakeHttpClient::ok(['balance' => ['merchant' => []]]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: new Retry(maxRetries: 0), env: []);

        $ob->account->balance();

        self::assertSame(2, $fake->count());
        $ts = (int) $fake->header(1, 'X-Timestamp');
        self::assertLessThan(5, abs($ts - $serverNow));
    }

    public function testTimesOutAndReportsTransportTimeout(): void
    {
        $fake = new FakeHttpClient([['delaySeconds' => 1]]);
        $ob = new Oblodai(...self::CREDS, http: $fake, timeoutMs: 20, retry: new Retry(maxRetries: 0), env: []);

        try {
            $ob->account->balance();
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame(TransportException::TIMEOUT, $e->errorCode);
        }
    }

    public function testUsesThePayoutCredentialsForPayoutRoutesWhenConfigured(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::ok(['uuid' => 'p', 'status' => 'pending', 'fee_bearer' => 'gateway']),
            FakeHttpClient::ok(['uuid' => 'i', 'status' => 'created']),
        ]);
        $ob = new Oblodai(
            ...self::CREDS,
            payoutPublicId: 'wk_test_1',
            payoutSecret: 's2',
            http: $fake,
            env: [],
        );

        $ob->payouts->create(['amount' => '1', 'currency' => 'USDT', 'address' => 'T', 'order_id' => 'o']);
        $ob->payments->create(['amount' => '1', 'currency' => 'USDT']);

        self::assertSame('wk_test_1', $fake->header(0, 'X-Public-Id'));
        self::assertSame('pk_test_1', $fake->header(1, 'X-Public-Id'));
    }

    public function testRefusesASignedRouteWithNoCredentialsButAllowsAPublicOne(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['currencies' => [], 'pricing_currencies' => []])]);
        $ob = new Oblodai(baseUrl: 'https://api.test', http: $fake, env: []);

        self::assertInstanceOf(Currencies::class, $ob->catalog->currencies());

        try {
            $ob->account->balance();
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame(ConfigException::MISSING_CREDENTIALS, $e->errorCode);
        }
    }

    public function testKeepsAPathPrefixOnBaseUrlAndSignsOverTheFullPath(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['balance' => ['merchant' => []]])]);
        $ob = new Oblodai(
            publicId: 'pk',
            secret: 's',
            baseUrl: 'https://gw.corp/oblodai/',
            http: $fake,
            env: [],
        );

        $ob->account->balance();

        self::assertSame('https://gw.corp/oblodai/v1/balance', $fake->calls[0]->url);
    }

    public function testDropsCallerHeadersThatCollideWithSignedHeaders(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['balance' => ['merchant' => []]])]);
        $ob = new Oblodai(
            publicId: 'pk',
            secret: 's',
            baseUrl: 'https://api.test',
            http: $fake,
            headers: ['x-signature' => 'zz', 'X-Trace' => 't1'],
            env: [],
        );

        $ob->account->balance();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $fake->header(0, 'x-signature'));
        self::assertSame('t1', $fake->header(0, 'x-trace'));
    }

    public function testRefusesPathParametersThatWouldRewriteTheUrl(): void
    {
        $fake = new FakeHttpClient([]);
        $ob = new Oblodai(baseUrl: 'https://api.test', http: $fake, env: []);

        foreach (['..', 'a/b', ''] as $bad) {
            try {
                $ob->payments->publicView($bad);
                self::fail(sprintf('expected a ConfigException for "%s"', $bad));
            } catch (ConfigException $e) {
                self::assertSame(ConfigException::BAD_PATH_PARAM, $e->errorCode);
            }
        }
        self::assertSame(0, $fake->count());
    }

    public function testSerializesErrorsWithoutTheRawBodyAndKeepsTheMessage(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::error(400, ['code' => 'payment.below_minimum', 'message' => 'too small', 'retryable' => false]),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        try {
            $ob->payments->create(['amount' => '0', 'currency' => 'USDT']);
            self::fail('expected a ValidationException');
        } catch (OblodaiException $e) {
            $json = json_decode((string) json_encode($e), true);
            self::assertIsArray($json);
            self::assertSame('payment.below_minimum', $json['code']);
            self::assertSame('too small', $json['message']);
            self::assertSame(400, $json['httpStatus']);
            self::assertArrayNotHasKey('raw', $json);
        }
    }

    public function testSendsUuidForDocumentReportsKeyedByBatchId(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::raw(200, '%PDF', ['content-type' => 'application/pdf'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $ob->documents->batchReport('b-1', ['format' => 'csv']);

        parse_str((string) parse_url($fake->calls[0]->url, PHP_URL_QUERY), $query);
        self::assertSame('b-1', $query['uuid']);
    }
}
