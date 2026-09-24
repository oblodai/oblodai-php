<?php

declare(strict_types=1);

namespace Oblodai\Core;

/**
 * Request and response hooks: plain callables the client calls once per attempt — for metrics,
 * tracing, structured logs. They run synchronously in the calling code, so keep them cheap; an
 * exception thrown by a hook propagates out of the call.
 *
 * ```php
 * $oblodai = new Oblodai(hooks: new Hooks(
 *     onResponse: fn (ResponseInfo $r) => $metrics->timing($r->request->operationId, $r->elapsed),
 * ));
 * ```
 */
final class Hooks
{
    /** @var (callable(RequestInfo): void)|null */
    public readonly mixed $onRequest;

    /** @var (callable(ResponseInfo): void)|null */
    public readonly mixed $onResponse;

    /**
     * @param (callable(RequestInfo): void)|null  $onRequest  called before every attempt
     * @param (callable(ResponseInfo): void)|null $onResponse called after every attempt, failed ones too
     */
    public function __construct(?callable $onRequest = null, ?callable $onResponse = null)
    {
        $this->onRequest = $onRequest;
        $this->onResponse = $onResponse;
    }
}
