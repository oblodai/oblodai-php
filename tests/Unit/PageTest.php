<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Contract\Model\Payment;
use Oblodai\Contract\Model\Payout;
use Oblodai\Core\RequestOptions;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
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
    private static function page(array $items, int $offset, int $total, int $perPage): array
    {
        return FakeHttpClient::ok([
            'items' => $items,
            'paginate' => [
                'total' => $total,
                'per_page' => $perPage,
                'offset' => $offset,
                'has_pages' => $offset + count($items) < $total,
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

    public function testACallerIdempotencyKeyIsNeverForwardedToListPages(): void
    {
        $fake = new FakeHttpClient([self::page([], 0, 0, 50)]);
        $ob = new Oblodai(publicId: 'p', secret: 's', baseUrl: 'https://api.test', http: $fake, env: []);

        $ob->payouts->history([], new RequestOptions(idempotencyKey: 'k'))->items();
        self::assertNull($fake->header(0, 'Idempotency-Key'));
    }
}
