<?php

declare(strict_types=1);

namespace Oblodai\Http;

use Oblodai\Exception\TransportException;

/**
 * The one thing the SDK needs from an HTTP stack: send this request, honour this per-attempt
 * timeout, hand back the status, headers and bytes — and never follow a redirect (the signature
 * covers the path, so a redirect must surface, not be chased).
 *
 * `CurlHttpClient` is the default; `Psr18HttpClient` adapts any PSR-18 client with PSR-17 factories.
 */
interface HttpClient
{
    /**
     * @param float $timeoutSeconds per-attempt budget; the implementation must abort past it
     *
     * @throws TransportException on timeout or any failure that produced no response
     */
    public function send(HttpRequest $request, float $timeoutSeconds): HttpResponse;
}
