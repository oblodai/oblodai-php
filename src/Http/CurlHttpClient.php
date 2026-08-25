<?php

declare(strict_types=1);

namespace Oblodai\Http;

use CurlHandle;
use Oblodai\Exception\TransportException;

/**
 * The default transport: ext-curl, no dependencies, one reusable handle per client instance.
 * Redirects are never followed and TLS verification is never disabled.
 */
final class CurlHttpClient implements HttpClient
{
    private ?CurlHandle $handle = null;

    /** @param array<int, mixed> $curlOptions extra cURL options (proxy, CA bundle, interface) */
    public function __construct(
        private readonly array $curlOptions = [],
        private readonly float $connectTimeoutSeconds = 10.0,
    ) {
    }

    public function send(HttpRequest $request, float $timeoutSeconds): HttpResponse
    {
        $handle = $this->handle ??= curl_init();
        if ($handle === false) {
            throw new TransportException(TransportException::NETWORK, 'could not initialise cURL');
        }
        curl_reset($handle);

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
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
        ];
        if ($request->body !== null && $request->method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        curl_setopt_array($handle, $options + $this->curlOptions);

        $body = curl_exec($handle);
        if ($body === false) {
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
            (string) $body
        );
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            curl_close($this->handle);
        }
    }
}
