<?php

declare(strict_types=1);

namespace Oblodai\Tests\Support;

use Oblodai\Exception\TransportException;
use Oblodai\Http\HttpClient;
use Oblodai\Http\HttpRequest;
use Oblodai\Http\HttpResponse;

/**
 * An HTTP stack that answers from a script and records every request it saw — the whole transport
 * (signing, retries, idempotency, skew) is exercised for real above it.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<HttpRequest> */
    public array $calls = [];

    /** @var list<float> per-call timeout the transport asked for, seconds */
    public array $timeouts = [];

    /** @var list<array<string, mixed>> */
    private array $script;

    /** @param list<array<string, mixed>> $script each entry: status, body, headers, throws, delayMs */
    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    /**
     * Queue `{state:0,result:…}` for the next call.
     *
     * @param  array<string, string> $headers
     * @return array<string, mixed>
     */
    public static function ok(mixed $result = [], array $headers = []): array
    {
        return ['status' => 200, 'body' => ['state' => 0, 'result' => $result], 'headers' => $headers];
    }

    /**
     * Queue an error envelope.
     *
     * @param  array<string, mixed>  $error
     * @param  array<string, string> $headers
     * @return array<string, mixed>
     */
    public static function error(int $status, array $error, array $headers = []): array
    {
        return ['status' => $status, 'body' => ['error' => $error], 'headers' => $headers];
    }

    /**
     * Queue an answer with no envelope at all — a proxy or load balancer talking.
     *
     * @param  array<string, string> $headers
     * @return array<string, mixed>
     */
    public static function raw(int $status, string $body, array $headers = []): array
    {
        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    /**
     * Queue a network failure.
     *
     * @return array<string, mixed>
     */
    public static function throws(string $code = TransportException::NETWORK, string $message = 'boom'): array
    {
        return ['throws' => [$code, $message]];
    }

    public function send(HttpRequest $request, float $timeoutSeconds): HttpResponse
    {
        $this->calls[] = $request;
        $this->timeouts[] = $timeoutSeconds;
        $next = array_shift($this->script);
        if ($next === null) {
            throw new TransportException(
                TransportException::NETWORK,
                sprintf('FakeHttpClient: no scripted response for %s %s', $request->method, $request->url)
            );
        }
        if (isset($next['delaySeconds']) && is_numeric($next['delaySeconds'])
            && (float) $next['delaySeconds'] > $timeoutSeconds) {
            throw new TransportException(
                TransportException::TIMEOUT,
                sprintf('request timed out after %d ms', (int) round($timeoutSeconds * 1000))
            );
        }
        if (isset($next['throws']) && is_array($next['throws'])) {
            /** @var array{0: string, 1: string} $throws */
            $throws = $next['throws'];

            throw new TransportException($throws[0], $throws[1]);
        }

        $body = $next['body'] ?? ['state' => 0, 'result' => []];
        $text = is_string($body) ? $body : (string) json_encode($body);
        $headers = ['content-type' => is_string($body) ? 'text/html' : 'application/json'];
        $scriptedHeaders = $next['headers'] ?? [];
        foreach (is_array($scriptedHeaders) ? $scriptedHeaders : [] as $name => $value) {
            $headers[strtolower((string) $name)] = is_scalar($value) ? (string) $value : '';
        }

        return new HttpResponse(is_int($next['status'] ?? null) ? $next['status'] : 200, $headers, $text);
    }

    /** Header of the n-th recorded request, case-insensitively. */
    public function header(int $index, string $name): ?string
    {
        $request = $this->calls[$index] ?? null;
        if ($request === null) {
            return null;
        }
        foreach ($request->headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Decoded JSON body of the n-th recorded request.
     *
     * @return array<string, mixed>
     */
    public function body(int $index): array
    {
        $raw = $this->calls[$index]->body ?? '';
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function count(): int
    {
        return count($this->calls);
    }
}
