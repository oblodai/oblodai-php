<?php

declare(strict_types=1);

namespace Oblodai\Core;

use Generator;
use IteratorAggregate;

/**
 * What a list method returns — a lazy handle; nothing is requested until it is consumed, and the
 * first page is fetched once however many ways it is consumed.
 *
 * - `foreach ($page as $item)` walks every item across every page;
 * - `$page->byPage()` yields every {@see PageResult} in turn, one request each;
 * - `$page->first()` (or `items()` / `paginate()`) is just the first page;
 * - `$page->all($max)` collects items into an array.
 *
 * `paginate.has_pages` is the gateway's own "there is more" flag; walking stops on it, or on a
 * page shorter than the limit, whichever comes first.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final class Page implements IteratorAggregate
{
    public const DEFAULT_LIMIT = 50;

    /** @var callable(int, int): PageResult<T> */
    private $fetchPage;

    private readonly int $limit;

    private readonly int $offset;

    /**
     * @param callable(int, int): PageResult<T> $fetchPage taking limit and offset
     * @param PageResult<T>|null                $first     the first page when it is already at hand
     */
    public function __construct(
        callable $fetchPage,
        ?int $limit = null,
        ?int $offset = null,
        private ?PageResult $first = null,
    ) {
        $this->fetchPage = $fetchPage;
        $this->limit = $limit ?? self::DEFAULT_LIMIT;
        $this->offset = $offset ?? 0;
    }

    /**
     * The first page, fetched once and cached.
     *
     * @return PageResult<T>
     */
    public function first(): PageResult
    {
        return $this->first ??= ($this->fetchPage)($this->limit, $this->offset);
    }

    /**
     * Items of the first page.
     *
     * @return list<T>
     */
    public function items(): array
    {
        return $this->first()->items;
    }

    /** Pagination block of the first page. */
    public function paginate(): Paginate
    {
        return $this->first()->paginate;
    }

    /**
     * Every page in turn, one request each (the first page is reused when already fetched).
     *
     * @return Generator<int, PageResult<T>>
     */
    public function byPage(): Generator
    {
        $offset = $this->offset;
        $page = $this->first();
        for (;;) {
            yield $page;
            $got = count($page->items);
            $offset += $got;
            // Two stops, and both are needed: `has_pages` is the gateway's own answer, and a page
            // shorter than the limit means the same thing. Without the second one, a server that
            // always sets `has_pages` (a bug, or a filtered count) would spin forever.
            if ($got === 0 || $got < $this->limit || !$page->paginate->has_pages) {
                return;
            }
            $page = ($this->fetchPage)($this->limit, $offset);
        }
    }

    /**
     * Every item across every page, fetched lazily.
     *
     * @return Generator<int, T>
     */
    public function getIterator(): Generator
    {
        foreach ($this->byPage() as $page) {
            foreach ($page->items as $item) {
                yield $item;
            }
        }
    }

    /**
     * Collect every item into an array (bounded by `$maxItems` when given).
     *
     * @return list<T>
     */
    public function all(?int $maxItems = null): array
    {
        $out = [];
        if ($maxItems !== null && $maxItems <= 0) {
            return $out;
        }
        foreach ($this as $item) {
            $out[] = $item;
            // Stop before the iterator resumes, so a cap never costs an extra page request.
            if ($maxItems !== null && count($out) >= $maxItems) {
                break;
            }
        }

        return $out;
    }
}
