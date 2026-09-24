<?php

declare(strict_types=1);

namespace Oblodai\Tests\Support;

use Oblodai\Core\FileResult;
use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;
use Oblodai\Core\Resource;
use Oblodai\Core\RouteSpec;

/**
 * A resource with any route: reaches the three runtime entry points directly, so the runtime is
 * tested on routes no generated method has (a paged route with a path parameter, say).
 */
final class ProbeResource extends Resource
{
    public static function route(
        string $method,
        string $path,
        bool $idempotent = false,
        bool $safe = false,
        bool $bare = false,
        ?string $listKind = null,
        string $operationId = 'probe',
        string $auth = 'key',
    ): RouteSpec {
        return new RouteSpec($operationId, $method, $path, $auth, $idempotent, $safe, $bare, $listKind);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string|int> $pathParams
     * @param array<string, mixed>      $query
     */
    public function call(
        RouteSpec $route,
        ?array $body = null,
        ?RequestOptions $options = null,
        array $pathParams = [],
        array $query = [],
        ?callable $parse = null,
    ): mixed {
        return $this->request($route, $body, $options, $pathParams, $query, $parse);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string|int> $pathParams
     * @param array<string, mixed>      $query
     *
     * @return Page<mixed>
     */
    public function page(
        RouteSpec $route,
        ?array $body = null,
        ?RequestOptions $options = null,
        array $pathParams = [],
        array $query = [],
        ?callable $parse = null,
    ): Page {
        return $this->requestPage($route, $body, $options, $pathParams, $query, $parse);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string|int> $pathParams
     * @param array<string, mixed>      $query
     */
    public function file(
        RouteSpec $route,
        ?array $body = null,
        ?RequestOptions $options = null,
        array $pathParams = [],
        array $query = [],
    ): FileResult {
        return $this->requestFile($route, $body, $options, $pathParams, $query);
    }
}
