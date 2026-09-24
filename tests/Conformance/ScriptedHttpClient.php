<?php

declare(strict_types=1);

namespace Oblodai\Tests\Conformance;

use Oblodai\Exception\TransportException;
use Oblodai\Http\HttpClient;
use Oblodai\Http\HttpRequest;
use Oblodai\Http\HttpResponse;
use RuntimeException;

/**
 * Replays a scenario's responses and records what the SDK sent: `{status, json?, headers?}` is an
 * answer (no `json` — a proxy's HTML page), `{transport_error: "timeout"}` a timeout.
 */
final class ScriptedHttpClient implements HttpClient
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @param list<array<string, mixed>> $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(HttpRequest $request, float $timeoutSeconds): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses)
            ?? throw new RuntimeException(sprintf('unscripted request %s %s', $request->method, $request->url));
        if (($next['transport_error'] ?? null) === 'timeout') {
            throw new TransportException(TransportException::TIMEOUT, 'scripted timeout');
        }
        $headers = [];
        foreach (is_array($next['headers'] ?? null) ? $next['headers'] : [] as $name => $value) {
            $headers[strtolower((string) $name)] = is_scalar($value) ? (string) $value : '';
        }
        $status = is_int($next['status'] ?? null) ? $next['status'] : 200;
        if (array_key_exists('json', $next)) {
            $headers['content-type'] ??= 'application/json';

            return new HttpResponse($status, $headers, (string) json_encode($next['json']));
        }
        $headers['content-type'] ??= 'text/html';

        return new HttpResponse($status, $headers, '<html>proxy</html>');
    }

    /**
     * `Idempotency-Key` of every request, null where none was sent.
     *
     * @return list<string|null>
     */
    public function keys(): array
    {
        return array_map(static function (HttpRequest $r): ?string {
            foreach ($r->headers as $name => $value) {
                if (strtolower($name) === 'idempotency-key') {
                    return $value;
                }
            }

            return null;
        }, $this->requests);
    }
}
