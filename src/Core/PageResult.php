<?php

declare(strict_types=1);

namespace Oblodai\Core;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * One page of a list: its items plus the gateway's pagination block. Iterating it walks THIS
 * page only; iterate the {@see Page} to walk every page.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final class PageResult implements IteratorAggregate, Countable
{
    /** @param list<T> $items */
    public function __construct(
        public readonly array $items,
        public readonly Paginate $paginate,
    ) {
    }

    /** Whether the gateway says there is more after this page. */
    public function hasMore(): bool
    {
        return $this->paginate->has_pages;
    }

    /** @return ArrayIterator<int, T> */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
