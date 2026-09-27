<?php

declare(strict_types=1);

namespace Oblodai\Core;

/** One attempt about to be sent, as a hook sees it. */
final class RequestInfo
{
    /** @param array<string, string> $headers as sent, with the signature and every credential header redacted */
    public function __construct(
        public readonly string $method,
        /** The URL as sent, with claim tokens in the path and signed-link `sig`/`exp`/`token` masked. */
        public readonly string $url,
        public readonly array $headers,
        /** 1 for the first attempt, 2 for the first retry, and so on. */
        public readonly int $attempt,
        /** `X-Request-ID` of the call; the same on every attempt. */
        public readonly string $requestId,
        /** The route's OpenAPI `operationId`. */
        public readonly string $operationId,
    ) {
    }
}
