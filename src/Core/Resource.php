<?php

declare(strict_types=1);

namespace Oblodai\Core;

use LogicException;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\ContractException;
use Oblodai\Generated\Routes;
use Oblodai\Generated\Signing;
use Oblodai\Lro;

/**
 * Base of every resource namespace on the client (`Oblodai\Generated\Resource\*`). Generated
 * methods call one of three entry points — `request()`, `requestPage()`, `requestFile()` — and
 * every method takes a {@see RequestOptions} as its last argument.
 *
 * Two wrappers work with any method of a resource and keep its return type intact:
 * - {@see Resource::withRawResponse()} — the status, headers and request id of the answer;
 * - {@see Resource::asJob()} — a waiter for a long-running operation ({@see Lro}).
 */
abstract class Resource
{
    /** Set on the copy the wrappers hand to their callback, never on the namespace itself. */
    private ?CallRecord $record = null;

    public function __construct(protected readonly Transport $transport)
    {
    }

    /**
     * Run one call of this resource and return its raw response; `parse()` gives the value the
     * method returned. The callback gets a copy of this resource.
     *
     * ```php
     * $raw = $oblodai->payments->withRawResponse(fn (Payments $p) => $p->create($invoice));
     * ```
     *
     * @template T
     *
     * @param callable(static): T $call
     *
     * @return RawResponse<T>
     */
    public function withRawResponse(callable $call): RawResponse
    {
        $copy = clone $this;
        $copy->record = new CallRecord();
        $value = $call($copy);
        $raw = $copy->record->raw
            ?? throw new LogicException('withRawResponse(): the callback made no call through the resource it was given');

        return $raw->withValue($value);
    }

    /**
     * Run a create call of a long-running operation ({@see Lro::JOBS}) and return its waiter.
     *
     * ```php
     * $job = $oblodai->documents->asJob(fn (Documents $d) => $d->createJob($export));
     * $job->wait();              // DocumentJobView with a terminal status
     * $file = $job->download();
     * ```
     *
     * @template T
     *
     * @param callable(static): T $call
     *
     * @return Job<T, mixed>
     */
    public function asJob(callable $call): Job
    {
        $copy = clone $this;
        $copy->record = new CallRecord();
        $value = $call($copy);
        $record = $copy->record;
        if ($record->route === null) {
            throw new LogicException('asJob(): the callback made no call through the resource it was given');
        }
        $plan = Lro::job($record->route->operationId);
        if ($plan === null) {
            throw new ConfigException(
                ConfigException::LRO_UNRESOLVED,
                sprintf('%s is not a long-running operation; asJob() wraps %s', $record->route->operationId, implode(', ', array_keys(Lro::JOBS))),
            );
        }
        $idField = $plan['idField'];
        $id = is_array($record->result) ? ($record->result[$idField] ?? null) : null;
        if (!is_string($id) && !is_int($id) || $id === '') {
            throw new ContractException(
                sprintf('long-running call %s answered without %s', $record->route->operationId, $idField),
                200,
                $record->result,
            );
        }
        $id = (string) $id;
        // The polls reuse the create call's timeout, retries and headers, never its idempotency
        // key (it belongs to the create) or its request id (each poll is a call of its own).
        $options = $record->options ?? new RequestOptions();
        $follow = new RequestOptions(timeout: $options->timeout, maxRetries: $options->maxRetries, extraHeaders: $options->extraHeaders);
        $transport = $this->transport;
        $pollRoute = Routes::get($plan['poll']);
        $model = $plan['model'];
        $poll = static function () use ($transport, $pollRoute, $idField, $id, $follow, $model): mixed {
            $answer = $transport->call($pollRoute, [$idField => $id], [], [], $follow);

            return $model !== null && method_exists($model, 'fromArray') ? $model::fromArray(self::object($answer, $pollRoute)) : $answer;
        };
        $download = null;
        if ($plan['download'] !== null) {
            $downloadRoute = Routes::get($plan['download']);
            $download = static fn (): FileResult => self::file(
                $transport->callRaw($downloadRoute, null, [$idField => $id], [], $follow)
            );
        }

        return new Job($id, $value, $poll, $plan['terminal'], $download, $transport->sleeper(), $plan['statusField']);
    }

    /**
     * Call an envelope route: its `result`, or `$parse(result)` when given.
     *
     * @template T
     *
     * @param array<string, mixed>|null                $body
     * @param array<string, string|int>                $pathParams
     * @param array<string, mixed>                     $query
     * @param (callable(array<string, mixed>): T)|null $parse
     *
     * @return ($parse is null ? mixed : T)
     */
    protected function request(
        RouteSpec $route,
        ?array $body,
        ?RequestOptions $options,
        #[\SensitiveParameter] array $pathParams = [],
        #[\SensitiveParameter] array $query = [],
        ?callable $parse = null,
    ): mixed {
        $options ??= new RequestOptions();
        if ($this->record !== null) {
            $raw = $this->transport->callRaw($route, $body, $query, $pathParams, $options);
            $result = $raw->parse();
            $this->record->add($route, $options, $raw, $result);
        } else {
            $result = $this->transport->call($route, $body, $query, $pathParams, $options);
        }

        return $parse === null ? $result : $parse(self::object($result, $route));
    }

    /**
     * Call a paged list route (`{items, paginate}`): a lazy {@see Page}. `limit`/`offset` in the
     * body (in the query for GET) pick the first page; the rest of the request repeats per page.
     *
     * @template T
     *
     * @param array<string, mixed>|null                $body
     * @param array<string, string|int>                $pathParams
     * @param array<string, mixed>                     $query
     * @param (callable(array<string, mixed>): T)|null $parse
     *
     * @return ($parse is null ? Page<mixed> : Page<T>)
     */
    protected function requestPage(
        RouteSpec $route,
        ?array $body,
        ?RequestOptions $options,
        #[\SensitiveParameter] array $pathParams = [],
        #[\SensitiveParameter] array $query = [],
        ?callable $parse = null,
    ): Page {
        $options ??= new RequestOptions();
        if ($options->idempotencyKey !== null && !$route->idempotent) {
            // One key reused across pages would make the gateway replay page 1 forever.
            throw new ConfigException(
                ConfigException::IDEMPOTENCY_UNSUPPORTED,
                sprintf(
                    '%s does not deduplicate by %s; remove idempotencyKey from this call',
                    $route->key(),
                    Signing::HEADER_IDEMPOTENCY_KEY
                ),
                'idempotencyKey'
            );
        }
        $options = $options->withoutIdempotencyKey();
        $viaQuery = $route->method === 'GET';
        $rest = $viaQuery ? $query : ($body ?? []);
        $limit = self::intOrNull($rest['limit'] ?? null);
        $offset = self::intOrNull($rest['offset'] ?? null);
        unset($rest['limit'], $rest['offset']);
        $transport = $this->transport;

        $fetch = static function (int $pageLimit, int $pageOffset) use (
            $transport,
            $route,
            $rest,
            $body,
            $query,
            $viaQuery,
            $pathParams,
            $options,
            $parse,
        ): PageResult {
            $paged = array_merge($rest, ['limit' => $pageLimit, 'offset' => $pageOffset]);
            $result = $transport->call(
                $route,
                $viaQuery ? $body : $paged,
                $viaQuery ? $paged : $query,
                $pathParams,
                $options
            );

            return self::pageOf($result, $route, $parse);
        };

        $first = null;
        if ($this->record !== null) {
            // A raw response is of the first page: fetch it now, and let the Page start from it.
            $paged = array_merge($rest, ['limit' => $limit ?? Page::DEFAULT_LIMIT, 'offset' => $offset ?? 0]);
            $raw = $transport->callRaw($route, $viaQuery ? $body : $paged, $viaQuery ? $paged : $query, $pathParams, $options);
            $result = $raw->parse();
            $this->record->add($route, $options, $raw, $result);
            $first = self::pageOf($result, $route, $parse);
        }

        return new Page($fetch, $limit, $offset, $first);
    }

    /**
     * Call a `bare` route: the file it answers with.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string|int> $pathParams
     * @param array<string, mixed>      $query
     */
    protected function requestFile(
        RouteSpec $route,
        ?array $body,
        ?RequestOptions $options,
        #[\SensitiveParameter] array $pathParams = [],
        #[\SensitiveParameter] array $query = [],
    ): FileResult {
        $options ??= new RequestOptions();
        $raw = $this->transport->callRaw($route, $body, $query, $pathParams, $options);
        $file = self::file($raw);
        $this->record?->add($route, $options, $raw, null);

        return $file;
    }

    /** @param RawResponse<mixed> $raw */
    private static function file(RawResponse $raw): FileResult
    {
        return new FileResult(
            $raw->body,
            $raw->header('content-type') ?? 'application/octet-stream',
            FileResult::filenameFrom($raw->header('content-disposition'))
        );
    }

    /**
     * @template T
     *
     * @param (callable(array<string, mixed>): T)|null $parse
     *
     * @return PageResult<mixed>
     */
    private static function pageOf(mixed $result, RouteSpec $route, ?callable $parse): PageResult
    {
        $page = Envelope::asPage($result);
        if ($parse === null) {
            return new PageResult($page['items'], $page['paginate']);
        }
        $items = [];
        foreach ($page['items'] as $item) {
            $items[] = $parse(self::object($item, $route));
        }

        return new PageResult($items, $page['paginate']);
    }

    /**
     * The gateway answers every modelled route with a JSON object; anything else is contract drift.
     *
     * @return array<string, mixed>
     */
    private static function object(mixed $value, RouteSpec $route): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ContractException(
                sprintf('%s: expected a JSON object in the result, got %s', $route->key(), get_debug_type($value)),
                200,
                $value,
            );
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+\z/', $value) === 1) ? (int) $value : null;
    }
}
