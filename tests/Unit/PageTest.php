<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Contract\Model\Payment;
use Oblodai\Contract\Model\Payout;
use Oblodai\Core\RequestOptions;
use Oblodai\Exception\ConfigException;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use Oblodai\Tests\Support\PagedProbe;
use PHPUnit\Framework\TestCase;

/** Ports test/unit/pagination.test.ts against Core\Page over a fake HTTP stack. */
final class PageTest extends TestCase
{
    /**
     * A minimal wire shape `Payment::fromArray()` accepts, identified by `uuid`.
     *
     * @return array<string, mixed>
     */
    private static function paymentRow(string $uuid): array
    {
        return ['uuid' => $uuid, 'status' => 'created'];
    }

    /**
     * A minimal wire shape `Payout::fromArray()` accepts, identified by `uuid`.
     *
     * @return array<string, mixed>
     */
    private static function payoutRow(string $uuid): array
    {
        return ['uuid' => $uuid, 'status' => 'pending', 'fee_bearer' => 'gateway'];
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
     * @param  list<Payment|Payout> $items
     * @return list<string>
     */
    private static function uuidsOf(array $items): array
    {
        return array_map(static fn (Payment|Payout $p): string => $p->uuid, $items);
    }

    public function testItemsFetchesExactlyOnePage(): void
    {
        $fake = new FakeHttpClient([self::page([self::paymentRow('1'), self::paymentRow('2')], 0, 5, 2)]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        $page = $ob->payments->history(['limit' => 2]);
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
        $first = $ob->payments->history(['limit' => 2]);
        self::assertSame(['1', '2'], self::uuidsOf($first->items()));
        self::assertSame(1, $fake->count());

        // … then a FRESH Page object walks every page from the start (script entries #2-4).
        $seen = [];
        foreach ($ob->payments->history(['limit' => 2]) as $item) {
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

        self::assertSame(['1', '2', '3'], self::uuidsOf($ob->payouts->history(['limit' => 2])->all()));
    }

    public function testAllCaps(): void
    {
        $fake = new FakeHttpClient([
            self::page([self::payoutRow('1'), self::payoutRow('2')], 0, 5, 2),
            self::page([self::payoutRow('3'), self::payoutRow('4')], 2, 5, 2),
        ]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        self::assertSame(['1', '2'], self::uuidsOf($ob->payouts->history(['limit' => 2])->all(2)));
    }

    public function testNothingIsRequestedUntilThePageIsConsumed(): void
    {
        $fake = new FakeHttpClient([self::page([], 0, 0, 50)]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        $ob->payments->history();
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
            $ob->payouts->history([], new RequestOptions(idempotencyKey: 'k'));
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
    public function testIterationStopsOnAShortPageEvenWhenTheServerKeepsClaimingMore(): void
    {
        $fake = new FakeHttpClient([
            self::page([self::paymentRow('a'), self::paymentRow('b')], 0, 999, 2, true),
            self::page([self::paymentRow('c')], 2, 999, 2, true),
            self::page([self::paymentRow('d')], 3, 999, 2, true),
        ]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        $seen = [];
        foreach ($ob->payments->history(['limit' => 2]) as $payment) {
            $seen[] = $payment->uuid;
        }

        self::assertSame(['a', 'b', 'c'], $seen);
        self::assertSame(2, $fake->count());
    }

    /**
     * A paged route reached through a path parameter must carry it on EVERY page. No shipped list
     * route has a placeholder yet, so the plumbing is probed directly: dropping the parameter turns
     * the second page into a request for `/v1/claim/` — or, as here, into a refusal to build it.
     */
    public function testPathParametersReachEveryPage(): void
    {
        $fake = new FakeHttpClient([
            self::page([['a' => 1]], 0, 4, 1, true),
            self::page([['b' => 2]], 1, 4, 1, false),
        ]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);
        $probe = new PagedProbe($ob->transport);

        $rows = $probe->paged('GET /v1/claim/{token}', ['limit' => 1], ['token' => 'tok-42'])->all();

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
        $probe = new PagedProbe($ob->transport);

        $this->expectException(ConfigException::class);
        $probe->paged('GET /v1/claim/{token}')->items();
    }
}
