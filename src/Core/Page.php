<?php

declare(strict_types=1);

namespace Oblodai\Core;

use Generator;
use IteratorAggregate;

/**
 * What a list method returns. `items()`/`paginate()` give the FIRST page; iterating the object
 * (`foreach`) walks EVERY page, fetching the next one only when the previous is exhausted.
 * Nothing is requested until the page is consumed, and the first page is fetched once however many
 * ways it is consumed.
 *
 * `paginate.has_pages` is the server's own "there is more" flag; iteration stops on it, or on a
 * short page, whichever comes first.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final class Page implements IteratorAggregate
{
    public const DEFAULT_LIMIT = 50;

    /** @var (callable(int, int): array{items: list<T>, paginate: Paginate}) */
    private $fetchPage;

    /** @var array{items: list<T>, paginate: Paginate}|null */
    private ?array $first = null;

    /** @param callable(int, int): array{items: list<T>, paginate: Paginate} $fetchPage taking limit and offset */
    public function __construct(callable $fetchPage, private readonly ?int $limit = null, private readonly ?int $offset = null)
    {
        $this->fetchPage = $fetchPage;
    }

    /**
     * Items of the first page.
     *
     * @return list<T>
     */
    public function items(): array
    {
        return $this->firstPage()['items'];
    }

    /** Pagination block of the first page. */
    public function paginate(): Paginate
    {
        return $this->firstPage()['paginate'];
    }

    /**
     * Every item across every page, fetched lazily.
     *
     * @return Generator<int, T>
     */
    public function getIterator(): Generator
    {
        $limit = $this->limit ?? self::DEFAULT_LIMIT;
        $offset = $this->offset ?? 0;
        $page = $this->firstPage();
        for (;;) {
            foreach ($page['items'] as $item) {
                yield $item;
            }
            $got = count($page['items']);
            $offset += $got;
            if ($got === 0 || !$page['paginate']->has_pages) {
                return;
            }
            $page = ($this->fetchPage)($limit, $offset);
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
        foreach ($this as $item) {
            if ($maxItems !== null && count($out) >= $maxItems) {
                break;
            }
            $out[] = $item;
        }

        return $out;
    }

    /** @return array{items: list<T>, paginate: Paginate} */
    private function firstPage(): array
    {
        return $this->first ??= ($this->fetchPage)($this->limit ?? self::DEFAULT_LIMIT, $this->offset ?? 0);
    }
}
