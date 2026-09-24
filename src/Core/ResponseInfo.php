<?php

declare(strict_types=1);

namespace Oblodai\Core;

use Oblodai\Exception\OblodaiException;

/** How one attempt ended: an HTTP response, or `status === 0` and a transport `error`. */
final class ResponseInfo
{
    /** @param array<string, string> $headers lower-cased names */
    public function __construct(
        public readonly RequestInfo $request,
        /** HTTP status, or 0 when the attempt produced no response (timeout, network error). */
        public readonly int $status,
        public readonly array $headers,
        /** Seconds from sending the attempt to this point. */
        public readonly float $elapsed,
        /** The error this attempt ended with (an error status or a transport failure), else null. */
        public readonly ?OblodaiException $error = null,
    ) {
    }
}
