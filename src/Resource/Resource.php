<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Request\RequestBody;
use Oblodai\Contract\Routes;
use Oblodai\Core\Envelope;
use Oblodai\Core\FileResult;
use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;
use Oblodai\Core\Transport;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\ContractException;

/**
 * Shared plumbing for the resource namespaces: turn a route key plus a body into a decoded model,
 * a lazily paged list, or a file. Every method's last argument is a `RequestOptions`.
 */
abstract class Resource
{
    public function __construct(protected readonly Transport $transport)
    {
    }

    /**
     * Call an envelope route and decode its `result` through `$decode`.
     *
     * @template T
     *
     * @param array<string, mixed>|RequestBody|null      $body
     * @param (callable(array<string, mixed>): T)|null   $decode
     * @param array<string, string|int>                  $pathParams
     * @param array<string, mixed>                       $query
     *
     * @return ($decode is null ? mixed : T)
     */
    protected function call(
        string $routeKey,
        array|RequestBody|null $body = null,
        ?RequestOptions $options = null,
        ?callable $decode = null,
        array $pathParams = [],
        array $query = [],
    ): mixed {
        $result = $this->transport->call(
            Routes::get($routeKey),
            self::bodyArray($body),
            $query,
            $pathParams,
            $options
        );

        return $decode === null ? $result : $decode(self::asObject($result, $routeKey));
    }

    /**
     * Call a paged list route (`{items, paginate}`). Nothing is requested until the page is used.
     *
     * @template T
     *
     * @param array<string, mixed>|RequestBody|null $params
     * @param callable(array<string, mixed>): T     $decodeItem
     * @param array<string, string|int>             $pathParams
     *
     * @return Page<T>
     */
    protected function page(
        string $routeKey,
        array|RequestBody|null $params,
        callable $decodeItem,
        ?RequestOptions $options = null,
        array $pathParams = [],
    ): Page {
        $route = Routes::get($routeKey);
        $params = self::bodyArray($params) ?? [];
        $limit = is_numeric($params['limit'] ?? null) ? (int) $params['limit'] : null;
        $offset = is_numeric($params['offset'] ?? null) ? (int) $params['offset'] : null;
        unset($params['limit'], $params['offset']);
        $options ??= new RequestOptions();
        if ($options->idempotencyKey !== null) {
            // Silently dropping it would be worse than refusing: the caller believes the pages are
            // deduplicated, and one key across every page would make the core replay page 1 forever.
            throw new ConfigException(
                ConfigException::IDEMPOTENCY_UNSUPPORTED,
                sprintf(
                    '%s does not deduplicate by Idempotency-Key (listing is not a write); remove '
                        . 'idempotencyKey from this call',
                    $routeKey
                ),
                'idempotencyKey'
            );
        }
        $options = $options->withoutIdempotencyKey();
        $transport = $this->transport;

        $fetch = static function (int $pageLimit, int $pageOffset) use (
            $transport,
            $route,
            $params,
            $pathParams,
            $decodeItem,
            $options
        ): array {
            $paging = ['limit' => $pageLimit, 'offset' => $pageOffset];
            $viaQuery = $route->method === 'GET';
            $result = $transport->call(
                $route,
                $viaQuery ? null : array_merge($params, $paging),
                $viaQuery ? array_merge($params, $paging) : [],
                $pathParams,
                $options
            );
            $page = Envelope::asPage($result);

            $items = [];
            foreach ($page['items'] as $item) {
                $items[] = $decodeItem(self::asObject($item, $route->key()));
            }

            return ['items' => $items, 'paginate' => $page['paginate']];
        };

        return new Page($fetch, $limit, $offset);
    }

    /**
     * Call a plain list route (`{items}` without `paginate`).
     *
     * @template T
     *
     * @param array<string, mixed>|RequestBody|null $body
     * @param callable(array<string, mixed>): T     $decodeItem
     *
     * @return list<T>
     */
    protected function plainList(
        string $routeKey,
        array|RequestBody|null $body,
        callable $decodeItem,
        ?RequestOptions $options = null,
    ): array {
        $result = $this->transport->call(Routes::get($routeKey), self::bodyArray($body), [], [], $options);

        $items = [];
        foreach (Envelope::asPlainList($result) as $item) {
            $items[] = $decodeItem(self::asObject($item, $routeKey));
        }

        return $items;
    }

    /**
     * Call a `bare` route and return its bytes.
     *
     * @param array<string, mixed>                  $query
     * @param array<string, string|int>             $pathParams
     * @param array<string, mixed>|RequestBody|null $body
     */
    protected function file(
        string $routeKey,
        array $query = [],
        array $pathParams = [],
        array|RequestBody|null $body = null,
        ?RequestOptions $options = null,
    ): FileResult {
        $response = $this->transport->callRaw(
            Routes::get($routeKey),
            self::bodyArray($body),
            $query,
            $pathParams,
            $options
        );

        return new FileResult(
            $response->body,
            $response->contentType() ?? 'application/octet-stream',
            FileResult::filenameFrom($response->header('content-disposition'))
        );
    }

    /**
     * The core answers every modelled route with a JSON object; anything else is contract drift.
     *
     * @return array<string, mixed>
     */
    protected static function asObject(mixed $value, string $routeKey): array
    {
        if (!is_array($value)) {
            throw new ContractException(
                sprintf('%s: expected a JSON object in the result, got %s', $routeKey, get_debug_type($value))
            );
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>|RequestBody|null $body
     * @return array<string, mixed>|null
     */
    protected static function bodyArray(array|RequestBody|null $body): ?array
    {
        if ($body instanceof RequestBody) {
            return $body->toArray();
        }

        /** @var array<string, mixed>|null $body */
        return $body;
    }

    /**
     * A bare string is taken as the object's id; an array is passed through.
     *
     * @param  array<string, mixed>|string $ref
     * @return array<string, mixed>
     */
    protected static function refBy(array|string $ref, string $key = 'uuid'): array
    {
        return is_string($ref) ? [$key => $ref] : $ref;
    }

    /** @param array<string, mixed>|string $ref */
    protected static function idOf(array|string $ref, string $key = 'uuid'): string
    {
        if (is_string($ref)) {
            return $ref;
        }
        $value = $ref[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
