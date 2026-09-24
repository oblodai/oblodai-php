<?php

declare(strict_types=1);

namespace Oblodai\Core;

use Oblodai\Exception\ApiException;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\ContractException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Exception\TransportException;
use Oblodai\Http\HttpClient;
use Oblodai\Http\HttpRequest;
use Oblodai\Http\HttpResponse;
use Oblodai\Log\Logger;
use Oblodai\Log\RedactingLogger;
use Oblodai\Log\Redactor;

/**
 * The HTTP engine every resource goes through. `callRaw` does the whole lifecycle — serialize →
 * sign → send (with timeout) → classify error → retry per policy — and hands back the successful
 * response; `call` also unwraps the `{state: 0, result}` envelope.
 *
 * One call has one `X-Request-ID` (the caller's `requestId`, else a fresh UUID), the same on every
 * attempt, and an error carries it when the gateway's envelope names none.
 */
final class Transport
{
    public const HEADER_REQUEST_ID = 'X-Request-ID';

    /** Codes that mean the core rejected the signature because of the timestamp or the MAC. */
    private const SIGNATURE_FAILURE_CODES = ['merchant.bad_signature', 'auth.bad_timestamp'];

    private readonly Retry $retry;
    private readonly Clock $clock;
    private readonly Logger $logger;

    /** @var callable(float): void */
    private $sleep;

    /**
     * @param array<string, string>        $headers extra headers on every request; never signed material
     * @param (callable(float): void)|null $sleep   pauses between attempts, seconds; injectable for tests
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly HttpClient $http,
        private readonly string $userAgent,
        /** The merchant's one API key; signs every route the core gates with `key`. */
        private readonly ?Credentials $credentials = null,
        /** Per-attempt timeout, seconds. */
        private readonly float $timeout = 30.0,
        /** Overall budget for a call including retries and pauses, seconds. */
        private readonly float $deadline = 90.0,
        ?Retry $retry = null,
        ?Clock $clock = null,
        ?Logger $logger = null,
        private readonly array $headers = [],
        /** Sent as `X-Admin-Token` on `onboard` routes only. */
        private readonly ?Secret $adminToken = null,
        private readonly ?Hooks $hooks = null,
        ?callable $sleep = null,
    ) {
        if (!($timeout > 0) || !($deadline > 0)) {
            throw new ConfigException(
                ConfigException::BAD_CONFIG,
                sprintf('timeout and deadline must be positive seconds (got %s and %s)', $timeout, $deadline),
                'timeout'
            );
        }
        $this->retry = $retry ?? new Retry();
        $this->clock = $clock ?? new Clock();
        $this->logger = RedactingLogger::wrap($logger);
        // A method reference, not a closure: a client stays serializable (and var_dump-able).
        $this->sleep = $sleep ?? [self::class, 'realSleep'];
    }

    /** The default pause: really sleeps. */
    public static function realSleep(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * A transport with these settings overridden, sharing this one's HTTP client, clock (and so its
     * learned skew), logger and hooks.
     *
     * @param array<string, string> $extraHeaders merged over the client's headers, case-insensitively
     */
    public function derive(int|float|null $timeout = null, ?int $maxRetries = null, array $extraHeaders = []): self
    {
        return new self(
            baseUrl: $this->baseUrl,
            http: $this->http,
            userAgent: $this->userAgent,
            credentials: $this->credentials,
            timeout: $timeout !== null ? (float) $timeout : $this->timeout,
            deadline: $this->deadline,
            retry: $maxRetries !== null ? $this->retry->with(['maxRetries' => $maxRetries]) : $this->retry,
            clock: $this->clock,
            logger: $this->logger,
            headers: self::mergeHeaders($this->headers, $extraHeaders),
            adminToken: $this->adminToken,
            hooks: $this->hooks,
            sleep: $this->sleep,
        );
    }

    /** @return callable(float): void the pause between attempts (and between polls of a job) */
    public function sleeper(): callable
    {
        return $this->sleep;
    }

    /** Per-attempt timeout, seconds. */
    public function timeout(): float
    {
        return $this->timeout;
    }

    /** The retry policy calls start from. */
    public function retry(): Retry
    {
        return $this->retry;
    }

    /** @return array<string, string> the headers sent on every request */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Call an envelope route and return its `result`.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
     * @param array<string, string|int> $pathParams
     */
    public function call(
        RouteSpec $route,
        ?array $body = null,
        array $query = [],
        array $pathParams = [],
        ?RequestOptions $options = null,
    ): mixed {
        return $this->callRaw($route, $body, $query, $pathParams, $options)->parse();
    }

    /**
     * Call a route and return its successful response; `parse()` unwraps the envelope's `result`
     * (a `bare` route's bytes stay in `body`).
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
     * @param array<string, string|int> $pathParams
     *
     * @return RawResponse<mixed>
     */
    public function callRaw(
        RouteSpec $route,
        ?array $body = null,
        array $query = [],
        array $pathParams = [],
        ?RequestOptions $options = null,
    ): RawResponse {
        $options ??= new RequestOptions();
        $requestId = $this->requestIdFor($options);
        $response = $this->execute($route, $body, $query, $pathParams, $options, $requestId);

        return new RawResponse(
            $route,
            $response,
            $requestId,
            static fn (RawResponse $raw): mixed => $route->bare ? $raw->body : self::unwrap($raw),
        );
    }

    /**
     * The `result` of a successful envelope response.
     *
     * @param RawResponse<mixed> $raw
     */
    public static function unwrap(RawResponse $raw): mixed
    {
        $decoded = Envelope::decode($raw->status, $raw->body, null, null, $raw->requestId);
        if ($decoded['ok'] !== true) {
            throw $decoded['error']; // unreachable: execute() already threw for error statuses
        }
        $result = $decoded['result'];
        // The core replays a cached response by Idempotency-Key; when the original was too large to
        // cache it answers {ok, idempotent_replay: true, detail} instead of the object — surface that.
        if (is_array($result) && ($result['idempotent_replay'] ?? null) === true) {
            throw new ContractException(
                sprintf(
                    '%s: the request was already processed but its response was too large to '
                        . 'replay — fetch the result by order_id/reference (%s)',
                    $raw->route->key(),
                    is_scalar($result['detail'] ?? null) ? (string) $result['detail'] : ''
                ),
                $raw->status,
                $result,
                requestId: $raw->requestId,
            );
        }

        return $result;
    }

    private function requestIdFor(RequestOptions $options): string
    {
        $id = $options->requestId
            ?? self::headerValue($options->extraHeaders, self::HEADER_REQUEST_ID)
            ?? self::headerValue($this->headers, self::HEADER_REQUEST_ID)
            ?? Util::uuid4();
        if (preg_match('/^[\x21-\x7e]{1,200}$/', $id) !== 1) {
            throw new ConfigException(
                ConfigException::BAD_HEADER,
                'requestId must be 1-200 printable ASCII characters without spaces',
                'requestId'
            );
        }

        return $id;
    }

    /** @param array<string, string> $headers */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === strtolower($name) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
     * @param array<string, string|int> $pathParams
     */
    private function execute(
        RouteSpec $route,
        ?array $body,
        array $query,
        array $pathParams,
        RequestOptions $options,
        string $requestId,
    ): HttpResponse {
        $serialized = RequestBuilder::serializeBody($body, $route->method);
        $idempotencyKey = $options->idempotencyKey;
        if ($idempotencyKey !== null) {
            Idempotency::assertKey($idempotencyKey);
            if (!$route->idempotent) {
                // The core ignores the header here, so a key would only make the SDK believe a
                // re-send is deduplicated when it is not — the one belief that turns a lost
                // response into a double spend.
                throw new ConfigException(
                    ConfigException::IDEMPOTENCY_UNSUPPORTED,
                    sprintf(
                        '%s does not deduplicate by Idempotency-Key; remove idempotencyKey from this call',
                        $route->key()
                    ),
                    'idempotencyKey'
                );
            }
        } elseif ($route->idempotent) {
            $idempotencyKey = Idempotency::newKey();
        }
        if ($options->maxRetries !== null && $options->maxRetries < 0) {
            throw new ConfigException(ConfigException::BAD_CONFIG, 'maxRetries must be >= 0', 'maxRetries');
        }
        if ($options->timeout !== null && !($options->timeout > 0)) {
            throw new ConfigException(ConfigException::BAD_CONFIG, 'timeout must be positive seconds', 'timeout');
        }
        $retry = $options->maxRetries !== null ? $this->retry->with(['maxRetries' => $options->maxRetries]) : $this->retry;
        $safeToRepeat = $route->safe || ($route->idempotent && $idempotencyKey !== null);
        $deadlineAt = Util::nowMs() + $this->deadline * 1000;
        $label = $route->key();
        $extraHeaders = self::mergeHeaders($this->headers, $options->extraHeaders);

        $attempt = 0;
        $skewTried = false;
        $skewBefore = 0;
        $skewInstalled = 0;
        for (;;) {
            // Sign with the offset as it stands NOW, and remember which offset that was: another
            // call sharing this client may correct the clock while this request is on the wire.
            [$ts, $signedOffset] = $this->clock->stamp();
            $request = RequestBuilder::build(
                baseUrl: $this->baseUrl,
                route: $route,
                pathParams: $pathParams,
                query: $query,
                body: $serialized,
                credentials: $this->credentials,
                idempotencyKey: $idempotencyKey,
                ts: $ts,
                userAgent: $this->userAgent,
                extraHeaders: $extraHeaders,
                adminToken: $this->adminToken?->reveal(),
                maxResponseBytes: $route->bare ? HttpRequest::MAX_FILE_BYTES : HttpRequest::MAX_JSON_BYTES,
                requestId: $requestId,
            );
            $this->logger->debug('request', ['route' => $label, 'attempt' => $attempt, 'requestId' => $requestId]);
            $info = new RequestInfo(
                $request->method,
                $request->url,
                self::redactHeaders($request->headers),
                $attempt + 1,
                $requestId,
                $route->operationId,
            );
            if ($this->hooks?->onRequest !== null) {
                ($this->hooks->onRequest)($info);
            }
            $sentAt = microtime(true);

            try {
                $response = $this->send($request, $options, $deadlineAt, $requestId);
            } catch (TransportException $err) {
                $this->responded($info, 0, [], $sentAt, $err);
                if ($retry->shouldRetry($err, $attempt, $safeToRepeat)) {
                    $this->pause($retry, $err, $attempt, $deadlineAt, $requestId);
                    ++$attempt;

                    continue;
                }

                throw $err;
            }

            try {
                $this->assertNotRedirected($request, $response, $requestId);
            } catch (OblodaiException $err) {
                $this->responded($info, $response->status, $response->headers, $sentAt, $err);

                throw $err;
            }

            if ($response->status >= 200 && $response->status < 300) {
                $this->responded($info, $response->status, $response->headers, $sentAt, null);

                return $response;
            }

            $failure = $this->classify($route, $response, $requestId);
            $this->responded($info, $response->status, $response->headers, $sentAt, $failure);
            $this->logger->debug('response', Redactor::redactFields([
                'route' => $label,
                'status' => $response->status,
                'code' => $failure->errorCode,
                'requestId' => $failure->requestId,
            ]));

            // Clock skew: the core rejected the timestamp/MAC. Learn its time from the `Date`
            // header, re-sign once, and keep the offset only if that attempt got past auth.
            if ($response->status === 401 && in_array($failure->errorCode, self::SIGNATURE_FAILURE_CODES, true)) {
                if (!$skewTried) {
                    $offset = $this->clock->observeServerDate($response->header('date'));
                    // Compare against the offset THIS request was signed with, not against whatever
                    // the shared clock says now: if a concurrent call already corrected it, this
                    // request simply retries with the corrected time instead of measuring again.
                    if ($offset !== null && abs($offset - $signedOffset) > Signer::SKEW_SECONDS / 2) {
                        $this->logger->warning('clock skew detected; re-signing with server time', [
                            'route' => $label,
                            'offsetSec' => $offset,
                        ]);
                        $skewTried = true;
                        $skewBefore = $signedOffset;
                        $skewInstalled = $offset;
                        $this->clock->correctIfUnchanged($signedOffset, $offset);

                        continue;
                    }
                    if ($offset !== null && $signedOffset !== $this->clock->offset()) {
                        continue; // someone else corrected the clock mid-flight; retry as signed now
                    }
                } elseif ($skewInstalled !== $skewBefore) {
                    // The corrected timestamp did not help. Roll back only while the shared offset
                    // is still the one this call installed — never undo a later, better correction.
                    $this->clock->correctIfUnchanged($skewInstalled, $skewBefore);
                }
            }

            if ($retry->shouldRetry($failure, $attempt, $safeToRepeat)) {
                $this->pause($retry, $failure, $attempt, $deadlineAt, $requestId);
                ++$attempt;

                continue;
            }

            throw $failure;
        }
    }

    /** @param array<string, string> $headers */
    private function responded(RequestInfo $info, int $status, array $headers, float $sentAt, ?OblodaiException $error): void
    {
        if ($this->hooks?->onResponse === null) {
            return;
        }
        ($this->hooks->onResponse)(new ResponseInfo($info, $status, $headers, microtime(true) - $sentAt, $error));
    }

    /**
     * @param  array<string, string> $headers
     * @return array<string, string>
     */
    private static function redactHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            $out[$name] = $lower === strtolower(Signer::HEADER_SIGNATURE) || $lower === strtolower(RequestBuilder::HEADER_ADMIN_TOKEN)
                ? '[redacted]'
                : $value;
        }

        return $out;
    }

    /**
     * Client-wide headers with the per-call ones on top. Names are matched case-insensitively, so
     * `x-shop` on a call replaces `X-Shop` on the client instead of travelling beside it.
     *
     * @param  array<string, string> $base
     * @param  array<string, string> $overrides
     * @return array<string, string>
     */
    private static function mergeHeaders(array $base, array $overrides): array
    {
        if ($overrides === []) {
            return $base;
        }
        $shadowed = array_map(strtolower(...), array_keys($overrides));
        $merged = [];
        foreach ($base as $name => $value) {
            if (!in_array(strtolower($name), $shadowed, true)) {
                $merged[$name] = $value;
            }
        }

        return $merged + $overrides;
    }

    /**
     * An HTTP stack that followed a redirect behind the SDK's back answered from a URL the caller
     * never signed for. The signature covers method + request URI, so the body cannot be trusted —
     * it is the same failure as a 3xx, and gets the same error.
     */
    private function assertNotRedirected(HttpRequest $request, HttpResponse $response, string $requestId): void
    {
        if ($response->finalUrl === null || $response->finalUrl === '' || $response->finalUrl === $request->url) {
            return;
        }

        throw ApiException::from(
            $response->status,
            [
                'code' => 'internal',
                'message' => sprintf(
                    'unexpected redirect: %s answered from %s; the HTTP client is following '
                        . 'redirects, which the SDK never does — check baseUrl and the client config',
                    $request->url,
                    $response->finalUrl
                ),
            ],
            $response->body,
            true,
            null,
            $requestId,
        );
    }

    private function classify(RouteSpec $route, HttpResponse $response, string $requestId): OblodaiException
    {
        $fallbackId = $response->header(self::HEADER_REQUEST_ID) ?? $requestId;

        try {
            $decoded = Envelope::decode(
                $response->status,
                $response->body,
                $response->header('retry-after'),
                $response->header('location'),
                $fallbackId,
            );
            if ($decoded['ok'] !== true) {
                return $decoded['error'];
            }
        } catch (OblodaiException $err) {
            return $err;
        }

        return new ContractException(
            sprintf('%s: HTTP %d with a success envelope', $route->key(), $response->status),
            $response->status,
            $response->body,
            requestId: $fallbackId,
        );
    }

    private function pause(Retry $retry, OblodaiException $error, int $attempt, float $deadlineAt, string $requestId): void
    {
        $ms = $retry->delayMs($error, $attempt);
        if (Util::nowMs() + $ms > $deadlineAt) {
            throw new TransportException(
                TransportException::DEADLINE,
                sprintf('retry would exceed the call deadline; last error: %s', $error->detail),
                $error,
                $requestId,
            );
        }
        if ($ms > 0) {
            ($this->sleep)($ms / 1000);
        }
    }

    private function send(HttpRequest $request, RequestOptions $options, float $deadlineAt, string $requestId): HttpResponse
    {
        $left = ($deadlineAt - Util::nowMs()) / 1000;
        if ($left <= 0) {
            throw new TransportException(
                TransportException::DEADLINE,
                'the call deadline elapsed before the request could be sent',
                null,
                $requestId,
            );
        }
        $timeout = min((float) ($options->timeout ?? $this->timeout), $left);

        try {
            return $this->http->send($request, max(0.001, $timeout));
        } catch (TransportException $err) {
            throw $err->requestId === null ? $err->withRequestId($requestId) : $err;
        }
    }
}
