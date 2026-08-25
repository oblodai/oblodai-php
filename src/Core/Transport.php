<?php

declare(strict_types=1);

namespace Oblodai\Core;

use Oblodai\Contract\RouteSpec;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\ContractException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Exception\TransportException;
use Oblodai\Http\HttpClient;
use Oblodai\Http\HttpRequest;
use Oblodai\Http\HttpResponse;
use Oblodai\Log\Logger;
use Oblodai\Log\NullLogger;
use Oblodai\Log\Redactor;

/**
 * The HTTP engine every resource goes through. One method, `call`, does the whole lifecycle:
 * serialize → sign → send (with timeout) → decode envelope → classify error → retry per policy.
 * `callRaw` serves the few `bare` routes that return bytes instead of JSON (PDF documents).
 */
final class Transport
{
    /** Codes that mean the core rejected the signature because of the timestamp or the MAC. */
    private const SIGNATURE_FAILURE_CODES = ['merchant.bad_signature', 'auth.bad_timestamp'];

    private readonly Retry $retry;
    private readonly Clock $clock;
    private readonly Logger $logger;

    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $baseUrl,
        private readonly HttpClient $http,
        private readonly string $userAgent,
        /** Used for `payment`/`any` routes, and for `payout` routes when no payout key exists. */
        private readonly ?Credentials $credentials = null,
        /** Optional second key pair for `payout` routes (the core issues separate key kinds). */
        private readonly ?Credentials $payoutCredentials = null,
        /** Per-attempt timeout, ms. */
        private readonly int $timeoutMs = 30000,
        /** Overall budget for a call including retries and pauses, ms. */
        private readonly int $deadlineMs = 90000,
        ?Retry $retry = null,
        ?Clock $clock = null,
        ?Logger $logger = null,
        /** Extra headers on every request. Never signed material. */
        private readonly array $headers = [],
        /** Sent as `X-Admin-Token` on `onboard` routes only. */
        private readonly ?string $adminToken = null,
    ) {
        $this->retry = $retry ?? new Retry();
        $this->clock = $clock ?? new Clock();
        $this->logger = $logger ?? new NullLogger();
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
        $raw = $this->execute($route, $body, $query, $pathParams, $options ?? new RequestOptions());
        $decoded = Envelope::decode($raw->status, $raw->body);
        if ($decoded['ok'] !== true) {
            throw $decoded['error']; // unreachable: execute() already threw for error statuses
        }
        $result = $decoded['result'];
        // The core replays a cached response by Idempotency-Key; when the original was too large to
        // cache it answers {ok, idempotent_replay: true, detail} instead of the object — surface that.
        if (is_array($result) && ($result['idempotent_replay'] ?? null) === true) {
            throw new ContractException(
                sprintf(
                    '%s %s: the request was already processed but its response was too large to '
                        . 'replay — fetch the result by order_id/reference (%s)',
                    $route->method,
                    $route->path,
                    is_scalar($result['detail'] ?? null) ? (string) $result['detail'] : ''
                ),
                $raw->status,
                $result
            );
        }

        return $result;
    }

    /**
     * Call a `bare` route and return the raw response (status already checked to be 2xx).
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
     * @param array<string, string|int> $pathParams
     */
    public function callRaw(
        RouteSpec $route,
        ?array $body = null,
        array $query = [],
        array $pathParams = [],
        ?RequestOptions $options = null,
    ): HttpResponse {
        return $this->execute($route, $body, $query, $pathParams, $options ?? new RequestOptions());
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
                        '%s %s does not deduplicate by Idempotency-Key; remove idempotencyKey from this call',
                        $route->method,
                        $route->path
                    ),
                    'idempotencyKey'
                );
            }
        } elseif ($route->idempotent) {
            $idempotencyKey = Idempotency::newKey();
        }
        $safeToRepeat = $route->safe || ($route->idempotent && $idempotencyKey !== null);
        $deadlineAt = Util::nowMs() + ($options->deadlineMs ?? $this->deadlineMs);
        $label = $route->method . ' ' . $route->path;

        $attempt = 0;
        $skewTried = false;
        $skewBefore = 0;
        for (;;) {
            $extraHeaders = $this->headers;
            if ($route->auth === 'onboard' && $this->adminToken !== null) {
                $extraHeaders['X-Admin-Token'] = $this->adminToken;
            }
            $request = RequestBuilder::build(
                baseUrl: $this->baseUrl,
                route: $route,
                pathParams: $pathParams,
                query: $query,
                body: $serialized,
                credentials: $this->credentialsFor($route, $options->preferPayoutKey),
                idempotencyKey: $idempotencyKey,
                ts: $this->clock->now(),
                userAgent: $this->userAgent,
                extraHeaders: $extraHeaders,
            );
            $this->logger->debug('request', ['route' => $label, 'attempt' => $attempt]);

            try {
                $response = $this->send($request, $options, $deadlineAt);
            } catch (TransportException $err) {
                if ($this->retry->shouldRetry($err, $attempt, $safeToRepeat)) {
                    $this->pause($err, $attempt, $deadlineAt);
                    ++$attempt;

                    continue;
                }

                throw $err;
            }

            if ($response->status >= 200 && $response->status < 300) {
                return $response;
            }

            $failure = $this->classify($route, $response);
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
                    if ($offset !== null && abs($offset - $this->clock->offset()) > Signer::SKEW_SECONDS / 2) {
                        $this->logger->warning('clock skew detected; re-signing with server time', [
                            'route' => $label,
                            'offsetSec' => $offset,
                        ]);
                        $skewTried = true;
                        $skewBefore = $this->clock->offset();
                        $this->clock->correct($offset);

                        continue;
                    }
                } else {
                    $this->clock->correct($skewBefore); // the corrected timestamp did not help
                }
            }

            if ($this->retry->shouldRetry($failure, $attempt, $safeToRepeat)) {
                $this->pause($failure, $attempt, $deadlineAt);
                ++$attempt;

                continue;
            }

            throw $failure;
        }
    }

    /** Which key pair signs a route. `any` routes take the payment key unless told otherwise. */
    private function credentialsFor(RouteSpec $route, bool $preferPayout): ?Credentials
    {
        if ($route->auth === 'payout' || ($route->auth === 'any' && $preferPayout)) {
            return $this->payoutCredentials ?? $this->credentials;
        }

        return $this->credentials;
    }

    private function classify(RouteSpec $route, HttpResponse $response): OblodaiException
    {
        try {
            $decoded = Envelope::decode(
                $response->status,
                $response->body,
                $response->header('retry-after'),
                $response->header('location')
            );
            if ($decoded['ok'] !== true) {
                return $decoded['error'];
            }
        } catch (OblodaiException $err) {
            return $err;
        }

        return new ContractException(
            sprintf('%s %s: HTTP %d with a success envelope', $route->method, $route->path, $response->status),
            $response->status,
            $response->body
        );
    }

    private function pause(OblodaiException $error, int $attempt, float $deadlineAt): void
    {
        $ms = $this->retry->delayMs($error, $attempt);
        if (Util::nowMs() + $ms > $deadlineAt) {
            throw new TransportException(
                TransportException::DEADLINE,
                sprintf('retry would exceed the call deadline; last error: %s', $error->getMessage()),
                $error
            );
        }
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    private function send(HttpRequest $request, RequestOptions $options, float $deadlineAt): HttpResponse
    {
        $left = $deadlineAt - Util::nowMs();
        if ($left <= 0) {
            throw new TransportException(
                TransportException::DEADLINE,
                'the call deadline elapsed before the request could be sent'
            );
        }
        $timeoutMs = min((float) ($options->timeoutMs ?? $this->timeoutMs), $left);

        return $this->http->send($request, max(0.001, $timeoutMs / 1000));
    }
}
