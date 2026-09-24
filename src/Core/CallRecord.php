<?php

declare(strict_types=1);

namespace Oblodai\Core;

/**
 * What a resource copy handed to {@see Resource::withRawResponse()} or {@see Resource::asJob()}
 * saw of the call its callback made: the route, the options, the raw response and the result.
 *
 * @internal
 */
final class CallRecord
{
    public ?RouteSpec $route = null;

    public ?RequestOptions $options = null;

    /** @var RawResponse<mixed>|null */
    public ?RawResponse $raw = null;

    public mixed $result = null;

    /** @param RawResponse<mixed> $raw */
    public function add(RouteSpec $route, RequestOptions $options, RawResponse $raw, mixed $result): void
    {
        $this->route = $route;
        $this->options = $options;
        $this->raw = $raw;
        $this->result = $result;
    }
}
