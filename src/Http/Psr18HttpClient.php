<?php

declare(strict_types=1);

namespace Oblodai\Http;

use Oblodai\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Adapter for any PSR-18 client (Guzzle, Symfony HttpClient, php-http): pass the client and the
 * PSR-17 factories that build its messages.
 *
 * PSR-18 has no per-request timeout, so the SDK's `timeoutMs` cannot be enforced here — configure
 * the timeout on the injected client itself. The overall per-call deadline still applies, and
 * `CurlHttpClient` (the default) honours both.
 */
final class Psr18HttpClient implements HttpClient
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(HttpRequest $request, float $timeoutSeconds): HttpResponse
    {
        $psr = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }
        if ($request->body !== null && $request->method !== 'GET') {
            $psr = $psr->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $response = $this->client->sendRequest($psr);
        } catch (NetworkExceptionInterface $e) {
            throw new TransportException(
                TransportException::NETWORK,
                'network error: ' . $e->getMessage(),
                $e
            );
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(
                TransportException::NETWORK,
                'HTTP client error: ' . $e->getMessage(),
                $e
            );
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return new HttpResponse($response->getStatusCode(), $headers, (string) $response->getBody());
    }
}
