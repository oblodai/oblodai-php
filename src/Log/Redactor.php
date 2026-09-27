<?php

declare(strict_types=1);

namespace Oblodai\Log;

use Oblodai\Generated\Signing;

/**
 * Replaces the values of sensitive-looking keys, recursively, without touching the original; and
 * masks the secrets a URL or a header set can carry.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    private const SENSITIVE = '/secret|signature|passcode|token|authorization|password|api[-_]?key|device[-_]?code|claim[-_]?url|^sig\z/i';

    /** Headers whose value is a credential (matched case-insensitively). */
    public const SECRET_HEADERS = [
        Signing::HEADER_SIGNATURE,
        'X-Admin-Token',
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'x-claim-passcode',
        'cookie',
    ];

    /** Path parameters that are bearer credentials (`/v1/claim/{token}`, `/v1/aml/{token}`). */
    private const SECRET_PATH_PARAMS = '/^\{(token|code|passcode)\}\z/i';

    /** Query parameters of a signed link (`/v1/documents/…?exp=&sig=`) or carrying a token. */
    private const SECRET_QUERY_PARAMS = ['sig', 'exp', 'token'];

    /**
     * A copy with every {@see self::SECRET_HEADERS} value replaced by `[redacted]`.
     *
     * @param  array<string, string> $headers
     * @return array<string, string>
     */
    public static function headers(#[\SensitiveParameter] array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[$name] = self::isSecretHeader((string) $name) ? self::REDACTED : $value;
        }

        return $out;
    }

    private static function isSecretHeader(string $name): bool
    {
        foreach (self::SECRET_HEADERS as $secret) {
            if (strcasecmp($name, $secret) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * A URL safe to show in hooks, logs and error messages: userinfo dropped, the path segments the
     * route template names `{token}`/`{code}`/`{passcode}` masked, and `sig`/`exp`/`token` query
     * values masked. Without a template only the query and userinfo are cleaned.
     */
    public static function url(#[\SensitiveParameter] string $url, ?string $template = null): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return self::REDACTED;
        }
        $segments = explode('/', $parts['path'] ?? '');
        if ($template !== null) {
            $tpl = explode('/', $template);
            $shift = count($segments) - count($tpl);
            foreach ($tpl as $i => $t) {
                if (preg_match(self::SECRET_PATH_PARAMS, $t) === 1 && $i + $shift >= 0) {
                    $segments[$i + $shift] = self::REDACTED;
                }
            }
        }
        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $pairs = [];
            foreach (explode('&', $parts['query']) as $pair) {
                $key = explode('=', $pair, 2)[0];
                $pairs[] = in_array(strtolower(rawurldecode($key)), self::SECRET_QUERY_PARAMS, true)
                    ? $key . '=' . self::REDACTED
                    : $pair;
            }
            $query = '?' . implode('&', $pairs);
        }
        $host = str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[')
            ? '[' . $parts['host'] . ']'
            : $parts['host'];

        return $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . implode('/', $segments) . $query;
    }

    /**
     * @param  array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function redactFields(array $fields): array
    {
        /** @var array<string, mixed> $redacted */
        $redacted = self::redact($fields);

        return $redacted;
    }

    public static function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_string($key) && preg_match(self::SENSITIVE, $key) === 1
                ? '[redacted]'
                : self::redact($item);
        }

        return $out;
    }
}
