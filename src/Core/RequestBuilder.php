<?php

declare(strict_types=1);

namespace Oblodai\Core;

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
    /** Headers the SDK owns; a caller-supplied header with one of these names is dropped. */
    private const RESERVED_HEADERS = [
        'x-public-id',
        'x-signature',
        'x-timestamp',
        'idempotency-key',
        'content-type',
        'content-length',
        'host',
    ];

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
    ): HttpRequest {
        $path = self::joinPath($baseUrl, self::fillPath($route->path, $pathParams));
        $queryString = self::buildQuery($query);
        $requestUri = $path . ($queryString === '' ? '' : '?' . $queryString);
        $url = self::origin($baseUrl) . $requestUri;

        $headers = [];
        foreach ($extraHeaders as $name => $value) {
            if (!in_array(strtolower((string) $name), self::RESERVED_HEADERS, true)) {
                $headers[(string) $name] = $value;
            }
        }
        $headers['Accept'] = 'application/json';
        $headers['User-Agent'] = $userAgent;
        $hasBody = $route->method !== 'GET';
        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers[Signer::HEADER_IDEMPOTENCY_KEY] = $idempotencyKey;
        }

        if ($route->auth !== 'public' && $route->auth !== 'onboard') {
            if ($credentials === null) {
                throw new ConfigException(
                    ConfigException::MISSING_CREDENTIALS,
                    sprintf(
                        '%s %s needs a %s API key: pass publicId/secret to new Oblodai() or set '
                            . 'OBLODAI_PUBLIC_ID / OBLODAI_SECRET',
                        $route->method,
                        $route->path,
                        $route->auth === 'any' ? 'merchant' : $route->auth
                    )
                );
            }
            $headers[Signer::HEADER_PUBLIC_ID] = $credentials->publicId;
            $headers[Signer::HEADER_TIMESTAMP] = (string) $ts;
            $headers[Signer::HEADER_SIGNATURE] = Signer::sign(
                $credentials->secret,
                $ts,
                $route->method,
                $requestUri,
                $idempotencyKey,
                $hasBody ? $body : ''
            );
        }

        return new HttpRequest($route->method, $url, $headers, $hasBody ? $body : null);
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
     * Serialize a request body once; unset fields vanish, a missing POST body becomes `{}`.
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

        return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
