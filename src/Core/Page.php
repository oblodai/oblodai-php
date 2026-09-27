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
 * Walking stops on an empty page, or once the offset reaches `paginate.total` — never because a
 * page came back shorter than the requested limit: the core clamps an out-of-range limit (to 25)
 * instead of refusing it, so a short page is not the last page.
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
            // Two stops: an empty page, and the offset reaching the reported total (which also ends
            // a server that keeps answering with pages past the end).
            if ($got === 0 || $offset >= $page->paginate->total) {
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
