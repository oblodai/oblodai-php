<?php

declare(strict_types=1);

namespace Oblodai\Tests\Support;

use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;
use Oblodai\Resource\Resource;

/**
 * Exposes `Resource::page()` so the paging plumbing itself can be tested, including the arguments
 * no shipped list route happens to use yet — a path parameter dropped here would only be found the
 * day the core paginates a route that has one.
 *
 * @internal test double
 */
final class PagedProbe extends Resource
{
    /**
     * @param  array<string, mixed>      $params
     * @param  array<string, string|int> $pathParams
     * @return Page<array<string, mixed>>
     */
    public function paged(
        string $routeKey,
        array $params = [],
        array $pathParams = [],
        ?RequestOptions $options = null,
    ): Page {
        return $this->page($routeKey, $params, static fn (array $row): array => $row, $options, $pathParams);
    }
}
