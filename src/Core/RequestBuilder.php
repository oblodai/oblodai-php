<?php

declare(strict_types=1);

namespace Oblodai\Core;

use JsonException;
use Oblodai\Contract\Routes;
use Oblodai\Contract\RouteSpec;
use Oblodai\Exception\ConfigException;
use Oblodai\Http\HttpRequest;

/**
 * Builds the outgoing request — URL, headers, body — as a pure function of its inputs, so the
 * signing material (what is signed) and the wire bytes (what is sent) come from one place and
 * cannot disagree. Nothing here touches the network or the clock.
 */
final class RequestBuilder
{
    /**
     * Headers the SDK owns. A caller-supplied header with one of these names is dropped, compared
     * case-insensitively — HTTP header names are case-insensitive, so letting `x-admin-token` sit
     * next to the SDK's `X-Admin-Token` would leave which one the server reads up to the transport.
     */
    private const RESERVED_HEADERS = [
        'accept',
        'content-type',
        'content-length',
        'host',
        'idempotency-key',
        'user-agent',
        'x-admin-token',
        'x-public-id',
        'x-signature',
        'x-timestamp',
    ];

    public const HEADER_ADMIN_TOKEN = 'X-Admin-Token';

    /** JSON flags used for every request body: exact bytes, and a hard failure instead of `false`. */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, string|int>   $pathParams
     * @param array<string, mixed>        $query
     * @param array<string, string>       $extraHeaders
     */
    public static function build(
        string $baseUrl,
        RouteSpec $route,
        array $pathParams = [],
        array $query = [],
        string $body = '',
        ?Credentials $credentials = null,
        ?string $idempotencyKey = null,
        int $ts = 0,
        string $userAgent = '',
        array $extraHeaders = [],
        ?string $adminToken = null,
        int $maxResponseBytes = HttpRequest::MAX_JSON_BYTES,
    ): HttpRequest {
        $path = self::joinPath($baseUrl, self::fillPath($route->path, $pathParams));
        $queryString = self::buildQuery($query);
        $requestUri = $path . ($queryString === '' ? '' : '?' . $queryString);
        $url = self::origin($baseUrl) . $requestUri;

        $headers = self::callerHeaders($extraHeaders);
        $headers['Accept'] = 'application/json';
        $headers['User-Agent'] = $userAgent;
        $hasBody = $route->method !== 'GET';
        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[Signer::HEADER_IDEMPOTENCY_KEY] = $idempotencyKey;
        }
        // The admin token provisions merchants on a self-hosted gateway; it is meaningless — and a
        // secret needlessly exposed — anywhere else, so it rides only on `onboard` routes.
        if ($adminToken !== null && $adminToken !== '' && $route->auth === 'onboard') {
            $headers[self::HEADER_ADMIN_TOKEN] = $adminToken;
        }

        if ($route->auth !== 'public' && $route->auth !== 'onboard') {
            if ($credentials === null) {
                throw new ConfigException(
                    ConfigException::MISSING_CREDENTIALS,
                    sprintf(
                        '%s %s must be signed: pass publicId/secret to new Oblodai() or set '
                            . 'OBLODAI_PUBLIC_ID / OBLODAI_SECRET',
                        $route->method,
                        $route->path
                    )
                );
            }
            $headers[Signer::HEADER_PUBLIC_ID] = $credentials->publicId;
            $headers[Signer::HEADER_TIMESTAMP] = (string) $ts;
            $headers[Signer::HEADER_SIGNATURE] = Signer::sign(
                $credentials->secret(),
                $ts,
                $route->method,
                $requestUri,
                $idempotencyKey,
                $hasBody ? $body : ''
            );
        }

        return new HttpRequest($route->method, $url, $headers, $hasBody ? $body : null, $maxResponseBytes);
    }

    /** Scheme and authority of the base URL (`https://host:port`). */
    public static function origin(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigException(ConfigException::BAD_CONFIG, sprintf('baseUrl is not a valid URL: %s', $baseUrl), 'baseUrl');
        }
        $host = str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[')
            ? '[' . $parts['host'] . ']'
            : $parts['host'];

        return $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** Append a route path to the base URL's own path prefix (`https://host/api` → `/api/v1/…`). */
    public static function joinPath(string $baseUrl, string $routePath): string
    {
        $prefix = rtrim((string) (parse_url($baseUrl, PHP_URL_PATH) ?? ''), '/');

        return $prefix . $routePath;
    }

    /**
     * Substitute `{name}` segments; every placeholder must be supplied, values are percent-encoded.
     *
     * @param array<string, string|int> $params
     */
    public static function fillPath(string $template, array $params = []): string
    {
        return (string) preg_replace_callback(
            '/\{([a-zA-Z_]+)\}/',
            static function (array $m) use ($template, $params): string {
                $name = $m[1];
                $value = isset($params[$name]) ? (string) $params[$name] : '';
                if ($value === '' || $value === '.' || $value === '..' || str_contains($value, '/')) {
                    throw new ConfigException(
                        ConfigException::BAD_PATH_PARAM,
                        sprintf(
                            'path parameter "%s" for %s must be a non-empty single segment (got %s)',
                            $name,
                            $template,
                            json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        ),
                        $name
                    );
                }

                return rawurlencode($value);
            },
            $template
        );
    }

    /** @param array<string, mixed> $query */
    public static function buildQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if (!is_scalar($value)) {
                throw new ConfigException(
                    ConfigException::BAD_CONFIG,
                    sprintf('query parameter "%s" must be a scalar', (string) $key),
                    (string) $key
                );
            }
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    /**
     * Caller-supplied headers, minus anything the SDK owns and minus anything unsendable.
     *
     * A header value carrying CR or LF is a request-splitting attempt (or a stray newline from a
     * config file); a non-ASCII value is not representable in an HTTP/1.1 field. Both are refused
     * here rather than mangled by the transport.
     *
     * @param  array<string, string> $extraHeaders
     * @return array<string, string>
     */
    private static function callerHeaders(array $extraHeaders): array
    {
        $headers = [];
        foreach ($extraHeaders as $name => $value) {
            $name = (string) $name;
            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                continue;
            }
            if (preg_match('/^[\x21-\x7e]+$/', $name) !== 1) {
                throw new ConfigException(
                    ConfigException::BAD_HEADER,
                    sprintf('header name %s is not a valid HTTP field name', json_encode($name)),
                    $name
                );
            }
            if (preg_match('/^[\x20-\x7e\t]*$/', $value) !== 1) {
                throw new ConfigException(
                    ConfigException::BAD_HEADER,
                    sprintf(
                        'header "%s" has a value with a line break or a non-ASCII character; HTTP '
                            . 'field values must be printable ASCII',
                        $name
                    ),
                    $name
                );
            }
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Serialize a request body once; unset fields vanish, a missing POST body becomes `{}`.
     *
     * A body that cannot be encoded (invalid UTF-8, NAN/INF, nesting past PHP's limit) is a
     * `ConfigException`, never an empty string: an empty body would be signed and sent as `{}`'s
     * evil twin and the core would answer about a request the caller never made.
     *
     * Floats are refused for every field the contract does not declare as a number. Amounts are
     * decimal strings precisely because `0.1 + 0.2` is not `0.3`, and PHP will happily serialize
     * the difference into a payout.
     *
     * @param array<string, mixed>|null $body
     */
    public static function serializeBody(?array $body, string $method): string
    {
        if ($method === 'GET') {
            return '';
        }
        if ($body === null || $body === []) {
            return '{}';
        }
        self::assertNoStrayFloats($body, '');

        try {
            return json_encode($body, self::JSON_FLAGS);
        } catch (JsonException $e) {
            throw new ConfigException(
                ConfigException::BAD_CONFIG,
                'request body cannot be encoded as JSON: ' . $e->getMessage(),
                'body'
            );
        }
    }

    /** @param array<mixed> $body */
    private static function assertNoStrayFloats(array $body, string $prefix): void
    {
        foreach ($body as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value)) {
                self::assertNoStrayFloats($value, $path);

                continue;
            }
            if (is_float($value) && !in_array((string) $key, Routes::NUMBER_FIELDS, true)) {
                throw new ConfigException(
                    ConfigException::BAD_CONFIG,
                    sprintf(
                        '"%s" was given as a float (%s); amounts and rates travel as decimal strings '
                            . "— pass '%s' instead",
                        $path,
                        var_export($value, true),
                        rtrim(rtrim(sprintf('%.18F', $value), '0'), '.')
                    ),
                    $path
                );
            }
        }
    }
}
