<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Core\PageResult;
use Oblodai\Core\RequestOptions;
use Oblodai\Exception\ConfigException;
use Oblodai\Generated\Model\PaymentView;
use Oblodai\Generated\Model\PayoutView;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use Oblodai\Tests\Support\ProbeResource;
use Oblodai\Tests\Support\Samples;
use PHPUnit\Framework\TestCase;

/** Paged lists (spec §3 item 7): `foreach` over every item, `byPage()` over every page. */
final class PageTest extends TestCase
{
    /**
     * A payment history row, identified by `uuid`.
     *
     * @return array<string, mixed>
     */
    private static function paymentRow(string $uuid): array
    {
        return Samples::of(PaymentView::class, ['uuid' => $uuid]);
    }

    /**
     * A payout history row, identified by `uuid`.
     *
     * @return array<string, mixed>
     */
    private static function payoutRow(string $uuid): array
    {
        return Samples::of(PayoutView::class, ['uuid' => $uuid]);
    }

    /**
     * @param  list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private static function page(array $items, int $offset, int $total, int $perPage, ?bool $hasPages = null): array
    {
        return FakeHttpClient::ok([
            'items' => $items,
            'paginate' => [
                'total' => $total,
                'per_page' => $perPage,
                'offset' => $offset,
                'has_pages' => $hasPages ?? ($offset + count($items) < $total),
            ],
        ]);
    }

    /**
     * @param  list<mixed> $items
     * @return list<string>
     */
    private static function uuidsOf(array $items): array
    {
        return array_map(static fn (mixed $p): string => $p instanceof PaymentView || $p instanceof PayoutView ? $p->uuid : '?', $items);
    }

    public function testItemsFetchesExactlyOnePage(): void
    {
        $fake = new FakeHttpClient([self::page([self::paymentRow('1'), self::paymentRow('2')], 0, 5, 2)]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        $page = $ob->payments->listHistory(['limit' => 2]);
        self::assertSame(['1', '2'], self::uuidsOf($page->items()));
        self::assertTrue($page->paginate()->has_pages);
        self::assertSame(1, $fake->count());
    }

    public function testForeachWalksEveryPageAndStopsOnHasPagesFalse(): void
    {
        $fake = new FakeHttpClient([
            self::page([self::paymentRow('1'), self::paymentRow('2')], 0, 5, 2),
            self::page([self::paymentRow('1'), self::paymentRow('2')], 0, 5, 2),
            self::page([self::paymentRow('3'), self::paymentRow('4')], 2, 5, 2),
            self::page([self::paymentRow('5')], 4, 5, 2),
        ]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        // Consume the first page once (script entry #1) …
        $first = $ob->payments->listHistory(['limit' => 2]);
        self::assertSame(['1', '2'], self::uuidsOf($first->items()));
        self::assertSame(1, $fake->count());

        // … then a FRESH Page object walks every page from the start (script entries #2-4).
        $seen = [];
        foreach ($ob->payments->listHistory(['limit' => 2]) as $item) {
            self::assertInstanceOf(PaymentView::class, $item);
            $seen[] = $item->uuid;
        }
        self::assertSame(['1', '2', '3', '4', '5'], $seen);
        self::assertSame(4, $fake->count());
        self::assertSame(['limit' => 2, 'offset' => 2], $fake->body(2));
    }

    public function testAllCollects(): void
    {
        $fake = new FakeHttpClient([
            self::page([self::payoutRow('1'), self::payoutRow('2')], 0, 3, 2),
            self::page([self::payoutRow('3')], 2, 3, 2),
        ]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        self::assertSame(['1', '2', '3'], self::uuidsOf($ob->payouts->listHistory(['limit' => 2])->all()));
    }

    public function testAllCaps(): void
    {
        $fake = new FakeHttpClient([
            self::page([self::payoutRow('1'), self::payoutRow('2')], 0, 5, 2),
            self::page([self::payoutRow('3'), self::payoutRow('4')], 2, 5, 2),
        ]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        self::assertSame(['1', '2'], self::uuidsOf($ob->payouts->listHistory(['limit' => 2])->all(2)));
    }

    public function testNothingIsRequestedUntilThePageIsConsumed(): void
    {
        $fake = new FakeHttpClient([self::page([], 0, 0, 50)]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        $ob->payments->listHistory();
        self::assertSame(0, $fake->count());
    }

    /**
     * A caller key on a list route is refused, not quietly dropped. Dropping it leaves the caller
     * believing the pages are deduplicated; forwarding it makes the core replay page 1 forever.
     */
    public function testACallerIdempotencyKeyOnAListRouteIsRefused(): void
    {
        $fake = new FakeHttpClient([self::page([], 0, 0, 50)]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        try {
            $ob->payouts->listHistory([], new RequestOptions(idempotencyKey: 'k'));
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('sdk.idempotency_unsupported', $e->errorCode);
            self::assertSame('idempotencyKey', $e->field);
        }
        self::assertSame(0, $fake->count(), 'nothing may be sent');
    }

    /**
     * A server that always claims `has_pages` (a bug, or a count filtered after paging) must not
     * spin the iterator forever: a page shorter than the limit ends the walk.
     */
    /**
     * A page shorter than the limit is not the last one — the core clamps an out-of-range limit to
     * 25 instead of refusing it. Walking goes on until an empty page (or the offset reaches the
     * total), so nothing is silently dropped.
     */
    public function testAShortPageIsNotTheLastPage(): void
    {
        $fake = new FakeHttpClient([
            self::page([self::paymentRow('a'), self::paymentRow('b')], 0, 999, 2, true),
            self::page([self::paymentRow('c')], 2, 999, 2, true),
            self::page([self::paymentRow('d')], 3, 999, 2, true),
            self::page([], 4, 999, 2, false),
        ]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        $seen = [];
        foreach ($ob->payments->listHistory(['limit' => 2]) as $payment) {
            self::assertInstanceOf(PaymentView::class, $payment);
            $seen[] = $payment->uuid;
        }

        self::assertSame(['a', 'b', 'c', 'd'], $seen);
        self::assertSame(4, $fake->count());
    }

    /**
     * A paged route reached through a path parameter must carry it on EVERY page. No shipped list
     * route has a placeholder yet, so the plumbing is probed directly: dropping the parameter turns
     * the second page into a request for `/v1/claim/` — or, as here, into a refusal to build it.
     */
    public function testPathParametersReachEveryPage(): void
    {
        $fake = new FakeHttpClient([
            self::page([['a' => 1]], 0, 2, 1, true),
            self::page([['b' => 2]], 1, 2, 1, false),
        ]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);
        $probe = new ProbeResource($ob->transport);
        $route = ProbeResource::route('GET', '/v1/claim/{token}', safe: true, listKind: 'paged');

        $rows = $probe->page($route, null, null, ['token' => 'tok-42'], ['limit' => 1])->all();

        self::assertCount(2, $rows);
        self::assertSame(2, $fake->count());
        foreach ($fake->calls as $call) {
            self::assertStringContainsString('/v1/claim/tok-42', $call->url);
        }
    }

    public function testAMissingPathParameterIsRefusedRatherThanSentAsAnEmptySegment(): void
    {
        $fake = new FakeHttpClient([self::page([['a' => 1]], 0, 1, 1)]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);
        $probe = new ProbeResource($ob->transport);

        $this->expectException(ConfigException::class);
        $probe->page(ProbeResource::route('GET', '/v1/claim/{token}', listKind: 'paged'))->items();
    }

    public function testByPageYieldsEveryPageWithItsPagination(): void
    {
        $fake = new FakeHttpClient([
            self::page([self::paymentRow('1'), self::paymentRow('2')], 0, 5, 2),
            self::page([self::paymentRow('3'), self::paymentRow('4')], 2, 5, 2),
            self::page([self::paymentRow('5')], 4, 5, 2),
        ]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        $pages = iterator_to_array($ob->payments->listHistory(['limit' => 2, 'offset' => 0])->byPage(), false);

        self::assertCount(3, $pages);
        self::assertContainsOnlyInstancesOf(PageResult::class, $pages);
        self::assertSame(['1', '2'], self::uuidsOf($pages[0]->items));
        self::assertSame(2, $pages[1]->paginate->offset);
        self::assertTrue($pages[1]->hasMore());
        self::assertFalse($pages[2]->hasMore());
        self::assertCount(1, $pages[2]);
        self::assertSame(['limit' => 2, 'offset' => 4], $fake->body(2));
    }

    public function testByPageReusesAFirstPageAlreadyFetched(): void
    {
        $fake = new FakeHttpClient([
            self::page([self::paymentRow('1')], 0, 1, 50),
        ]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        $page = $ob->payments->listHistory();
        $first = $page->first();
        $pages = iterator_to_array($page->byPage(), false);

        self::assertSame($first, $pages[0]);
        self::assertSame(1, $fake->count());
    }

    public function testAGetListSendsPagingOnTheQueryString(): void
    {
        $fake = new FakeHttpClient([self::page([], 0, 0, 10)]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        $ob->sandbox->listWebhooks(limit: 10)->items();

        self::assertSame('https://api.test/v1/sandbox/webhooks?limit=10&offset=0', $fake->calls[0]->url);
        self::assertNull($fake->calls[0]->body);
    }
}
