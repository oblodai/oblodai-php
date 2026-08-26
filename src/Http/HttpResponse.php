<?php

declare(strict_types=1);

namespace Oblodai\Http;

/** One HTTP response as the SDK needs it: status, lower-cased headers and the raw body bytes. */
final class HttpResponse
{
    /** @param array<string, string> $headers keys lower-cased */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
        /**
         * The URL the body actually came from. Equal to the requested URL unless the HTTP stack
         * followed a redirect behind the SDK's back — which the transport treats as an error,
         * because the signature covers the path that was requested, not the one that answered.
         * Null when the implementation cannot report it.
         */
        public readonly ?string $finalUrl = null,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function contentType(): ?string
    {
        return $this->header('content-type');
    }
}
