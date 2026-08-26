<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Core\Clock;
use Oblodai\Core\FileResult;
use Oblodai\Core\Retry;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Http\HttpRequest;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the transport must refuse to do: send a body it could not encode, send a float where an
 * amount belongs, let a caller header shadow a signed one, trust a body that came from another URL,
 * or let two concurrent calls fight over the clock offset.
 */
final class TransportHardeningTest extends TestCase
{
    private const CREDS = ['publicId' => 'pk', 'secret' => 's', 'baseUrl' => 'https://api.test'];

    private static function fastRetry(int $maxRetries = 2): Retry
    {
        return new Retry(maxRetries: $maxRetries, baseDelayMs: 1, maxDelayMs: 2);
    }

    public function testABodyThatCannotBeEncodedIsRefusedInsteadOfSignedAsAnEmptyString(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['uuid' => 'p'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        try {
            // Invalid UTF-8: json_encode() returns false, and `(string) false` is the empty string —
            // which would be signed and sent as a body the caller never wrote.
            $ob->payments->create(['order_id' => "bad \xB1\x31", 'amount' => '1', 'currency' => 'USDT']);
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('sdk.bad_config', $e->errorCode);
            self::assertSame('body', $e->field);
        }
        self::assertSame(0, $fake->count(), 'nothing may reach the wire');
    }

    /** @return iterable<string, array{mixed}> */
    public static function unencodableValues(): iterable
    {
        yield 'NAN' => [NAN];
        yield 'INF' => [INF];
    }

    #[DataProvider('unencodableValues')]
    public function testNonFiniteNumbersAreRefused(mixed $value): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok([])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $this->expectException(ConfigException::class);
        $ob->payments->create(['amount' => '1', 'currency' => 'USDT', 'accuracy_payment_percent' => $value]);
    }

    public function testAFloatAmountIsRefusedWithTheDecimalStringSpelledOut(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['uuid' => 'p'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        try {
            $ob->payments->create(['amount' => 0.1 + 0.2, 'currency' => 'USDT']);
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('amount', $e->field);
            self::assertStringContainsString('decimal strings', $e->getMessage());
            self::assertStringContainsString('0.30000000000000004', $e->getMessage());
        }
        self::assertSame(0, $fake->count());
    }

    public function testAFloatNestedInsideABatchItemIsRefusedToo(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok([])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        try {
            $ob->payments->batch(['payments' => [['order_id' => 'o1', 'amount' => 25.0, 'currency' => 'USDT']]]);
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('payments.0.amount', $e->field);
        }
    }

    /** The one field the contract really declares as a number still goes through. */
    public function testTheContractsOwnNumberFieldIsStillAccepted(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['uuid' => 'p'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $ob->payments->create(['amount' => '1', 'currency' => 'USDT', 'accuracy_payment_percent' => 1.5]);

        self::assertSame(1.5, $fake->body(0)['accuracy_payment_percent'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function reservedHeaderSpellings(): iterable
    {
        yield 'lower case' => ['x-signature'];
        yield 'upper case' => ['X-SIGNATURE'];
        yield 'mixed case' => ['X-sIgNaTuRe'];
    }

    #[DataProvider('reservedHeaderSpellings')]
    public function testACallerHeaderNeverShadowsASignedOneWhateverItsCase(string $spelling): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['uuid' => 'p'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, headers: [$spelling => 'forged'], env: []);

        $ob->payments->create(['amount' => '1', 'currency' => 'USDT']);

        foreach ($fake->calls[0]->headers as $name => $value) {
            self::assertNotSame('forged', $value, sprintf('caller header "%s" survived as %s', $spelling, $name));
        }
        self::assertNotSame('forged', $fake->header(0, 'X-Signature'));
    }

    /**
     * The admin token provisions merchants; it is a secret with no business on a payment route,
     * and a caller-supplied one must not travel either.
     */
    public function testTheAdminTokenRidesOnlyOnOnboardRoutesAndNeverFromACallerHeader(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::ok(['uuid' => 'p']),
            FakeHttpClient::ok([
                'merchant_id' => 'm', 'project_id' => 'p',
                'api_key' => ['public_id' => 'a', 'secret' => 'b', 'kind' => 'any'],
                'payment_key' => ['public_id' => 'a', 'secret' => 'b', 'kind' => 'payment'],
                'payout_key' => ['public_id' => 'c', 'secret' => 'd', 'kind' => 'payout'],
            ]),
        ]);
        $ob = new Oblodai(
            ...self::CREDS,
            adminToken: 'adm',
            http: $fake,
            headers: ['x-admin-token' => 'forged'],
            env: [],
        );

        $ob->payments->create(['amount' => '1', 'currency' => 'USDT']);
        self::assertNull($fake->header(0, 'X-Admin-Token'), 'a payment route must not carry the admin token');

        $ob->merchants->create(['email' => 'owner@shop.example']);
        self::assertSame('adm', $fake->header(1, 'X-Admin-Token'));
    }

    /** @return iterable<string, array{string}> */
    public static function unsendableHeaderValues(): iterable
    {
        yield 'CR' => ["a\rb"];
        yield 'LF' => ["a\nb"];
        yield 'CRLF injection' => ["a\r\nX-Signature: forged"];
        yield 'non-ascii' => ['naïve'];
    }

    #[DataProvider('unsendableHeaderValues')]
    public function testAHeaderValueWithALineBreakOrNonAsciiIsRefused(string $value): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::ok(['uuid' => 'p'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, headers: ['X-Shop' => $value], env: []);

        try {
            $ob->payments->create(['amount' => '1', 'currency' => 'USDT']);
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('X-Shop', $e->field);
        }
        self::assertSame(0, $fake->count());
    }

    /**
     * A stack that follows redirects answers from a URL the signature never covered. The SDK does
     * not follow redirects itself; when an injected client did, the body is refused.
     */
    public function testABodyThatCameFromAnotherUrlIsRefused(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::redirected('https://evil.test/v1/payment', ['uuid' => 'p']),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, retry: self::fastRetry(0), env: []);

        try {
            $ob->payments->create(['amount' => '1', 'currency' => 'USDT']);
            self::fail('expected an OblodaiException');
        } catch (OblodaiException $e) {
            self::assertStringContainsString('unexpected redirect', $e->getMessage());
            self::assertStringContainsString('evil.test', $e->getMessage());
            self::assertTrue($e->synthetic);
        }
    }

    public function testAResponseFromTheRequestedUrlIsFine(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::redirected('https://api.test/v1/payment', ['uuid' => 'p']),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        self::assertSame('p', $ob->payments->create(['amount' => '1', 'currency' => 'USDT'])->uuid);
    }

    public function testJsonRoutesAndFileRoutesCarryDifferentBodyCeilings(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::ok(['uuid' => 'p']),
            FakeHttpClient::raw(200, '%PDF-1.7', ['content-type' => 'application/pdf']),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $ob->payments->create(['amount' => '1', 'currency' => 'USDT']);
        $ob->documents->statement(['from' => '2026-01-01', 'to' => '2026-01-31']);

        self::assertSame(HttpRequest::MAX_JSON_BYTES, $fake->calls[0]->maxResponseBytes);
        self::assertSame(HttpRequest::MAX_FILE_BYTES, $fake->calls[1]->maxResponseBytes);
        self::assertGreaterThan($fake->calls[0]->maxResponseBytes, $fake->calls[1]->maxResponseBytes);
    }

    public function testSavingADocumentToAnUnwritablePathFailsLoudlyInsteadOfReturningZero(): void
    {
        $file = new FileResult('%PDF-1.7', 'application/pdf', 'statement.pdf');

        $this->expectException(ConfigException::class);
        $file->saveTo('/proc/definitely/not/writable/statement.pdf');
    }
}
