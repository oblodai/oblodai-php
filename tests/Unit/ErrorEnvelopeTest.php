<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Core\Envelope;
use Oblodai\Exception\ApiException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Exception\RateLimitException;
use Oblodai\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The error envelope is decoded field by field. A gateway, a proxy or a partially-migrated core can
 * mangle any one field; losing the other five with it — or throwing a native TypeError while
 * decoding an error — turns a handled failure into an unhandled one.
 */
final class ErrorEnvelopeTest extends TestCase
{
    /** @param array<string, mixed> $detail */
    private static function decode(int $status, array $detail, ?string $retryAfterHeader = null): OblodaiException
    {
        $decoded = Envelope::decode(
            $status,
            (string) json_encode(['error' => $detail]),
            $retryAfterHeader
        );
        self::assertFalse($decoded['ok']);

        return $decoded['error'];
    }

    public function testOneMangledFieldDoesNotCostTheOthers(): void
    {
        // `field` is an array and `retryable` is the string "yes": neither is usable, and both are
        // ignored — but the code, the message and the request id still arrive.
        $error = self::decode(400, [
            'code' => 'payment.bad_amount',
            'message' => 'amount must be positive',
            'field' => ['amount'],
            'retryable' => 'yes',
            'request_id' => 'req-7',
        ]);

        self::assertInstanceOf(ValidationException::class, $error);
        self::assertSame('payment.bad_amount', $error->errorCode);
        self::assertSame('amount must be positive', $error->getMessage());
        self::assertSame('req-7', $error->requestId);
        self::assertNull($error->field);
        self::assertFalse($error->retryable, 'a non-boolean retryable falls back to the status default');
        self::assertFalse($error->synthetic);
    }

    public function testANonStringCodeMeansNoUsableEnvelopeButKeepsAStringRequestId(): void
    {
        $error = self::decode(500, ['code' => ['nested'], 'message' => 'boom', 'request_id' => 'req-9']);

        self::assertSame('internal', $error->errorCode);
        self::assertTrue($error->synthetic);
        self::assertSame('req-9', $error->requestId);
        self::assertTrue($error->retryable, '500 without an envelope is transient');
    }

    public function testANonStringMessageFallsBackToTheStatus(): void
    {
        $error = self::decode(409, ['code' => 'idempotency.key_reused', 'message' => 42]);

        self::assertSame('HTTP 409', $error->getMessage());
        self::assertSame('idempotency.key_reused', $error->errorCode);
    }

    public function testRetryableIsHonouredOnlyAsALiteralBoolean(): void
    {
        self::assertTrue(self::decode(400, ['code' => 'x.y', 'retryable' => true])->retryable);
        self::assertFalse(self::decode(503, ['code' => 'x.y', 'retryable' => false])->retryable);
        // 1 is not `true`; the status decides instead — and 503 defaults to retryable.
        self::assertTrue(self::decode(503, ['code' => 'x.y', 'retryable' => 1])->retryable);
        self::assertFalse(self::decode(400, ['code' => 'x.y', 'retryable' => 1])->retryable);
        self::assertTrue(self::decode(429, ['code' => 'x.y'])->retryable);
    }

    /** @return iterable<string, array{mixed, ?int}> */
    public static function retryAfterValues(): iterable
    {
        yield 'integer' => [30, 30];
        yield 'float' => [2.7, 2];
        yield 'numeric string' => ['45', 45];
        yield 'padded numeric string' => ['  12  ', 12];
        yield 'negative' => [-5, 0];
        yield 'huge' => [1.0e30, OblodaiException::MAX_RETRY_AFTER_SECONDS];
        yield 'huge string' => ['999999999999999999999999', OblodaiException::MAX_RETRY_AFTER_SECONDS];
        yield 'boolean' => [true, null];
        yield 'array' => [[1], null];
        yield 'garbage string' => ['soon', null];
        yield 'empty string' => ['', null];
        yield 'null' => [null, null];
    }

    #[DataProvider('retryAfterValues')]
    public function testRetryAfterIsClampedOrDropped(mixed $value, ?int $expected): void
    {
        self::assertSame($expected, ApiException::retryAfterSeconds($value));
    }

    public function testAGarbageRetryAfterInTheEnvelopeFallsBackToTheHeader(): void
    {
        $error = self::decode(429, ['code' => 'request.rate_limited', 'retry_after' => 'soon'], '17');

        self::assertInstanceOf(RateLimitException::class, $error);
        self::assertSame(17, $error->retryAfter);
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function retryAfterHeaders(): iterable
    {
        yield 'delta seconds' => ['20', 20];
        yield 'padded' => ['  20 ', 20];
        yield 'past http date' => ['Sun, 06 Nov 1994 08:49:37 GMT', 0];
        yield 'far future http date' => ['Fri, 31 Dec 9999 23:59:59 GMT', OblodaiException::MAX_RETRY_AFTER_SECONDS];
        yield 'garbage' => ['whenever', null];
        yield 'empty' => ['', null];
        yield 'negative' => ['-3', null];
    }

    #[DataProvider('retryAfterHeaders')]
    public function testTheRetryAfterHeaderIsClampedTheSameWay(string $header, ?int $expected): void
    {
        self::assertSame($expected, Envelope::parseRetryAfter($header, 1_700_000_000));
    }

    public function testAnHttpDateInTheFutureBecomesWholeSeconds(): void
    {
        $now = (int) strtotime('Mon, 01 Jan 2035 00:00:00 GMT');
        self::assertSame(120, Envelope::parseRetryAfter('Mon, 01 Jan 2035 00:02:00 GMT', $now));
    }

    public function testABodyThatIsNotAnObjectIsStillClassifiedByStatus(): void
    {
        $decoded = Envelope::decode(502, '<html>bad gateway</html>', null);
        self::assertFalse($decoded['ok']);
        self::assertTrue($decoded['error']->synthetic);
        self::assertTrue($decoded['error']->retryable);
        self::assertSame('internal', $decoded['error']->errorCode);
    }

    public function testABigIntegerSurvivesDecodingIntactRatherThanBecomingAFloat(): void
    {
        $decoded = Envelope::decode(200, '{"state":0,"result":{"sequence":123456789012345678901}}');

        self::assertTrue($decoded['ok']);
        /** @var array<string, mixed> $result */
        $result = $decoded['result'];
        self::assertSame('123456789012345678901', $result['sequence']);
    }
}
