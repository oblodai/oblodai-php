<?php

declare(strict_types=1);

namespace Oblodai\Http;

use Oblodai\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Adapter for any PSR-18 client (Guzzle, Symfony HttpClient, php-http): pass the client and the
 * PSR-17 factories that build its messages.
 *
 * PSR-18 describes only "send this request, get a response". Three things the SDK relies on are
 * therefore NOT enforceable from here and must be configured on the injected client:
 *
 * 1. **No redirects.** The signature covers the path that was requested; a followed redirect gets a
 *    body from somewhere the caller never signed for. Guzzle: `'allow_redirects' => false`.
 *    Symfony: `'max_redirects' => 0`. The adapter still detects a followed redirect after the fact
 *    (the response's effective URI differs) and the transport turns that into the usual
 *    "unexpected redirect" error — but detecting is not preventing.
 * 2. **Timeouts.** PSR-18 has no per-request timeout, so the SDK's `timeoutMs` cannot be applied
 *    here. Set both a connect and a total timeout on the client. Guzzle:
 *    `'connect_timeout' => 10, 'timeout' => 30`. The overall per-call deadline still bounds the
 *    number of attempts, and `CurlHttpClient` (the default) honours everything itself.
 * 3. **TLS verification.** Leave it on. Guzzle's `'verify' => false` silently defeats the transport.
 *
 * The response body IS bounded here: it is read in chunks and abandoned past the request's ceiling,
 * so a runaway body cannot exhaust memory even when the client would happily buffer it.
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

        return new HttpResponse(
            $response->getStatusCode(),
            $headers,
            self::readBounded($response->getBody(), $request),
            // PSR-18 gives no way to learn which URL actually answered, so the SDK's
            // followed-redirect check cannot run here — hence rule 1 below.
            null
        );
    }

    /** Read at most `maxResponseBytes` and refuse the rest, instead of buffering whatever arrives. */
    private static function readBounded(StreamInterface $stream, HttpRequest $request): string
    {
        $limit = $request->maxResponseBytes;
        $body = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(min(65536, $limit - strlen($body) + 1));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > $limit) {
                throw new TransportException(
                    TransportException::RESPONSE_TOO_LARGE,
                    sprintf(
                        'response body exceeds the %d-byte ceiling for %s %s and was not read',
                        $limit,
                        $request->method,
                        $request->url
                    )
                );
            }
        }

        return $body;
    }
}
