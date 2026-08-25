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
