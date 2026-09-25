<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use LogicException;
use Oblodai\Core\FileResult;
use Oblodai\Core\Hooks;
use Oblodai\Core\Page;
use Oblodai\Core\RequestInfo;
use Oblodai\Core\RequestOptions;
use Oblodai\Core\ResponseInfo;
use Oblodai\Core\Retry;
use Oblodai\Exception\OblodaiException;
use Oblodai\Exception\TransportException;
use Oblodai\Generated\Model\PaymentView;
use Oblodai\Generated\Resource\Documents;
use Oblodai\Generated\Resource\Payments;
use Oblodai\Generated\Signing;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use Oblodai\Tests\Support\Operations;
use PHPUnit\Framework\TestCase;

/** The raw response, a client copy with options, and request/response hooks (spec §3 item 5). */
final class RawOptionsHooksTest extends TestCase
{
    private const CREDS = ['publicId' => 'pk', 'secret' => 's', 'baseUrl' => 'https://api.test'];

    public function testWithRawResponseGivesStatusHeadersRequestIdAndTheParsedValue(): void
    {
        $fake = new FakeHttpClient([
            FakeHttpClient::sample('createPayment', ['uuid' => 'u-7'], ['x-ratelimit-remaining' => '42']),
        ]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $raw = $ob->payments->withRawResponse(
            static fn (Payments $p): PaymentView => $p->create(['amount' => '1', 'currency' => 'USDT'], new RequestOptions(requestId: 'rq-1'))
        );

        self::assertSame(200, $raw->status);
        self::assertSame('42', $raw->header('X-RateLimit-Remaining'));
        self::assertSame('42', $raw->headers['x-ratelimit-remaining']);
        self::assertSame('rq-1', $raw->requestId);
        self::assertSame('createPayment', $raw->route->operationId);
        self::assertStringContainsString('"u-7"', $raw->body);
        $invoice = $raw->parse();
        self::assertInstanceOf(PaymentView::class, $invoice);
        self::assertSame('u-7', $invoice->uuid);
        self::assertSame($invoice, $raw->parse(), 'parsed once');
    }

    public function testTheResponseRequestIdWinsOverTheSentOne(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::sample('getBalance', [], ['X-Request-ID' => 'srv-9'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $raw = $ob->account->withRawResponse(static fn ($a) => $a->getBalance());

        self::assertSame('srv-9', $raw->requestId);
    }

    public function testAnErrorStatusStillThrowsThroughTheRawWrapper(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::error(404, ['code' => 'payment.not_found', 'retryable' => false])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $this->expectException(OblodaiException::class);
        $ob->payments->withRawResponse(static fn (Payments $p) => $p->getInfo(['uuid' => 'nope']));
    }

    public function testARawListIsOfItsFirstPageAndStillWalksOn(): void
    {
        $item = Operations::sampleResult('listPaymentHistory');
        self::assertIsArray($item);
        $page1 = $item;
        $page1['paginate'] = ['total' => 2, 'per_page' => 1, 'offset' => 0, 'has_pages' => true];
        $page2 = $item;
        $page2['paginate'] = ['total' => 2, 'per_page' => 1, 'offset' => 1, 'has_pages' => false];
        $fake = new FakeHttpClient([FakeHttpClient::ok($page1, ['x-total' => '2']), FakeHttpClient::ok($page2)]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $raw = $ob->payments->withRawResponse(static fn (Payments $p): Page => $p->listHistory(['limit' => 1]));

        self::assertSame(1, $fake->count(), 'the first page is fetched for the raw response');
        self::assertSame('2', $raw->header('x-total'));
        $page = $raw->parse();
        self::assertCount(2, $page->all());
        self::assertSame(2, $fake->count(), 'the first page is not fetched twice');
    }

    public function testARawFileKeepsItsBytes(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::raw(200, '%PDF-1.7', ['content-type' => 'application/pdf', 'content-disposition' => 'attachment; filename="b.pdf"'])]);
        $ob = new Oblodai(...self::CREDS, http: $fake, env: []);

        $raw = $ob->documents->withRawResponse(static fn (Documents $d): FileResult => $d->getBalance());

        self::assertSame('%PDF-1.7', $raw->body);
        self::assertSame('b.pdf', $raw->parse()->filename);
    }

    public function testACallbackThatMakesNoCallIsAMistake(): void
    {
        $ob = new Oblodai(...self::CREDS, http: new FakeHttpClient([]), env: []);

        $this->expectException(LogicException::class);
        $ob->payments->withRawResponse(static fn (Payments $p): int => 1);
    }

    public function testWithOptionsMakesACopyAndLeavesTheOriginalAlone(): void
    {
        $down = FakeHttpClient::error(503, ['code' => 'db.unavailable', 'retryable' => true, 'retry_after' => 0]);
        $fake = new FakeHttpClient([$down, $down, FakeHttpClient::sample('getBalance'), FakeHttpClient::sample('getBalance')]);
        $ob = new Oblodai(
            ...self::CREDS,
            http: $fake,
            timeout: 30,
            retry: new Retry(maxRetries: 3, baseDelayMs: 1, maxDelayMs: 2),
            headers: ['X-Shop' => 'one'],
            env: [],
        );

        $fast = $ob->withOptions(timeout: 5, maxRetries: 0, extraHeaders: ['x-shop' => 'two', 'X-Extra' => 'e']);

        try {
            $fast->account->getBalance();
            self::fail('expected an OblodaiException');
        } catch (OblodaiException) {
        }
        self::assertSame(1, $fake->count(), 'the copy does not retry');
        self::assertEqualsWithDelta(5.0, $fake->timeouts[0], 0.001);
        self::assertSame('two', $fake->header(0, 'X-Shop'));
        self::assertSame('e', $fake->header(0, 'X-Extra'));

        $ob->account->getBalance();
        self::assertSame(3, $fake->count(), 'the original still retries');
        self::assertEqualsWithDelta(30.0, $fake->timeouts[2], 0.001);
        self::assertSame('one', $fake->header(2, 'X-Shop'));
        self::assertNull($fake->header(2, 'X-Extra'));

        self::assertNotSame($ob->payments, $fast->payments);
        self::assertSame($ob->config, $fast->config);
    }

    public function testHooksSeeEveryAttemptWithoutTheSignature(): void
    {
        /** @var list<RequestInfo> $requests */
        $requests = [];
        /** @var list<ResponseInfo> $responses */
        $responses = [];
        $fake = new FakeHttpClient([
            FakeHttpClient::throws(TransportException::NETWORK, 'reset'),
            FakeHttpClient::sample('getBalance'),
        ]);
        $ob = new Oblodai(
            ...self::CREDS,
            http: $fake,
            retry: new Retry(maxRetries: 2, baseDelayMs: 1, maxDelayMs: 2),
            hooks: new Hooks(
                onRequest: static function (RequestInfo $r) use (&$requests): void {
                    $requests[] = $r;
                },
                onResponse: static function (ResponseInfo $r) use (&$responses): void {
                    $responses[] = $r;
                },
            ),
            env: [],
        );

        $ob->account->getBalance(new RequestOptions(requestId: 'rq-h'));

        self::assertCount(2, $requests);
        self::assertSame([1, 2], [$requests[0]->attempt, $requests[1]->attempt]);
        self::assertSame('getBalance', $requests[0]->operationId);
        self::assertSame('POST', $requests[0]->method);
        self::assertSame('https://api.test/v1/balance', $requests[0]->url);
        self::assertSame('rq-h', $requests[1]->requestId);
        self::assertSame('[redacted]', $requests[0]->headers[Signing::HEADER_SIGNATURE]);
        self::assertSame('pk', $requests[0]->headers[Signing::HEADER_PUBLIC_ID]);

        self::assertCount(2, $responses);
        self::assertSame(0, $responses[0]->status);
        self::assertInstanceOf(TransportException::class, $responses[0]->error);
        self::assertSame(200, $responses[1]->status);
        self::assertNull($responses[1]->error);
        self::assertSame($requests[1], $responses[1]->request);
        self::assertGreaterThanOrEqual(0.0, $responses[1]->elapsed);
    }

    public function testAHookThatThrowsStopsTheCall(): void
    {
        $fake = new FakeHttpClient([FakeHttpClient::sample('getBalance')]);
        $ob = new Oblodai(
            ...self::CREDS,
            http: $fake,
            hooks: new Hooks(onRequest: static function (): void {
                throw new \RuntimeException('tracer down');
            }),
            env: [],
        );

        $this->expectExceptionMessage('tracer down');
        $ob->account->getBalance();
    }

    public function testTheClientCopyKeepsTheHooks(): void
    {
        $seen = 0;
        $fake = new FakeHttpClient([FakeHttpClient::sample('getBalance')]);
        $ob = new Oblodai(
            ...self::CREDS,
            http: $fake,
            hooks: new Hooks(onResponse: static function () use (&$seen): void {
                ++$seen;
            }),
            env: [],
        );

        $ob->withOptions(timeout: 1)->account->getBalance();

        self::assertSame(1, $seen);
    }
}
