<?php

declare(strict_types=1);

namespace Oblodai\Http;

use CurlHandle;
use Oblodai\Exception\TransportException;

/**
 * The default transport: ext-curl, no dependencies.
 *
 * Redirects are never followed (the signature covers the path, so a redirect must surface) and TLS
 * verification is never disabled — both are set after the caller's own cURL options, so neither can
 * be turned off from outside. The response is streamed through a counter and abandoned the moment it
 * passes the request's ceiling, so an endless body costs a request, not the process.
 *
 * A handle is reused between calls, but only while it is idle: under Fibers or Swoole two calls on
 * one client can be in flight at once, and a shared handle would interleave their bytes. The second
 * caller quietly gets its own handle.
 */
final class CurlHttpClient implements HttpClient
{
    private ?CurlHandle $handle = null;
    private bool $busy = false;

    /** @param array<int, mixed> $curlOptions extra cURL options (proxy, CA bundle, interface) */
    public function __construct(
        private readonly array $curlOptions = [],
        private readonly float $connectTimeoutSeconds = 10.0,
    ) {
    }

    public function send(HttpRequest $request, float $timeoutSeconds): HttpResponse
    {
        $reuse = !$this->busy;
        if ($reuse) {
            $handle = $this->handle ??= curl_init();
            $this->busy = true;
        } else {
            $handle = curl_init();
        }
        if ($handle === false) {
            $this->busy = $this->busy && !$reuse;

            throw new TransportException(TransportException::NETWORK, 'could not initialise cURL');
        }

        try {
            return $this->exchange($handle, $request, $timeoutSeconds);
        } finally {
            if ($reuse) {
                $this->busy = false;
            } else {
                curl_close($handle);
            }
        }
    }

    private function exchange(CurlHandle $handle, HttpRequest $request, float $timeoutSeconds): HttpResponse
    {
        curl_reset($handle);

        if ($request->url === '' || $request->method === '') {
            throw new TransportException(
                TransportException::NETWORK,
                'refusing to send a request without a method or a URL'
            );
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $responseHeaders = [];
        $body = '';
        $overflowed = false;
        $limit = $request->maxResponseBytes;

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Without NOSIGNAL, cURL's DNS resolver uses alarm()/SIGALRM, which is not safe in a
            // process that has its own signal handlers (php-fpm, Swoole workers).
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ACCEPT_ENCODING => '',
            // The whole exchange, not just the connect: a server that dribbles a byte a second must
            // still hit the per-attempt budget.
            CURLOPT_TIMEOUT_MS => (int) max(1, round($timeoutSeconds * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => (int) max(1, round(min($this->connectTimeoutSeconds, $timeoutSeconds) * 1000)),
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
            // Returning short of the chunk length aborts the transfer — that is how the ceiling is
            // enforced without ever holding more than the ceiling in memory.
            CURLOPT_WRITEFUNCTION => static function ($_handle, string $chunk) use (
                &$body,
                &$overflowed,
                $limit
            ): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    $overflowed = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($request->body !== null && $request->method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        // The caller's options go first so the SDK's own can override them: neither redirects nor
        // certificate verification are negotiable.
        curl_setopt_array($handle, $this->curlOptions);
        curl_setopt_array($handle, $options);

        $ok = curl_exec($handle);
        if ($overflowed) {
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
        if ($ok === false) {
            $errno = curl_errno($handle);
            $message = curl_error($handle);
            $timedOut = in_array($errno, [CURLE_OPERATION_TIMEOUTED, 28], true);

            throw new TransportException(
                $timedOut ? TransportException::TIMEOUT : TransportException::NETWORK,
                $timedOut
                    ? sprintf('request timed out after %d ms', (int) round($timeoutSeconds * 1000))
                    : sprintf('network error: %s (cURL %d)', $message, $errno)
            );
        }

        /** @var array<string, string> $responseHeaders */
        return new HttpResponse(
            (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            $responseHeaders,
            $body,
            (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL)
        );
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            curl_close($this->handle);
        }
    }
}
