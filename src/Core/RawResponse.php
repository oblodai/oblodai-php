<?php

declare(strict_types=1);

namespace Oblodai\Core;

use Oblodai\Http\HttpResponse;

/**
 * The raw side of a successful call: status, headers, request id and body; `parse()` gives the
 * value the method returns. An error status still throws, exactly as without it.
 *
 * ```php
 * $raw = $oblodai->payments->withRawResponse(fn (Payments $p) => $p->create($invoice));
 * $raw->status;       // 200
 * $raw->requestId;    // the X-Request-ID of this call
 * $invoice = $raw->parse();
 * ```
 *
 * @template T
 */
final class RawResponse
{
    public readonly int $status;

    /** @var array<string, string> lower-cased names */
    public readonly array $headers;

    /** The response bytes, as received. */
    public readonly string $body;

    /** The response's `X-Request-ID`, else the one this SDK sent with the call. */
    public readonly string $requestId;

    /** @var callable(self<mixed>): T */
    private $decode;

    private bool $parsed = false;

    /** @var T|null */
    private mixed $value = null;

    /** @param callable(self<mixed>): T $decode turns the response into the method's value */
    public function __construct(
        public readonly RouteSpec $route,
        HttpResponse $response,
        string $sentRequestId,
        callable $decode,
    ) {
        $this->status = $response->status;
        $this->headers = $response->headers;
        $this->body = $response->body;
        $this->requestId = $response->header(Transport::HEADER_REQUEST_ID) ?? $sentRequestId;
        $this->decode = $decode;
    }

    /** One header, looked up case-insensitively. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The value the method returns without the raw response (computed once).
     *
     * @return T
     */
    public function parse(): mixed
    {
        if (!$this->parsed) {
            $this->value = ($this->decode)($this);
            $this->parsed = true;
        }

        /** @var T */
        return $this->value;
    }

    /**
     * The same response carrying an already computed value.
     *
     * @template V
     *
     * @param V $value
     *
     * @return self<V>
     */
    public function withValue(mixed $value): self
    {
        $http = new HttpResponse($this->status, $this->headers, $this->body);

        return new self($this->route, $http, $this->requestId, static fn (): mixed => $value);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'requestId' => $this->requestId, 'route' => $this->route->key()];
    }
}
