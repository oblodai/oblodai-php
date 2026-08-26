<?php

declare(strict_types=1);

namespace Oblodai\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Small shared helpers: UUIDs, constant-time comparison, header lookup, clock in milliseconds. */
final class Util
{
    /** RFC 4122 v4 UUID from the platform CSPRNG. */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /** Constant-time string equality. */
    public static function constantTimeEquals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /**
     * Case-insensitive header lookup over any of the header shapes a PHP framework may hand us:
     * `['X-Webhook-Id' => 'v']`, `['x-webhook-id' => ['v']]` or PHP's own `$_SERVER` style.
     *
     * @param array<string, mixed> $headers
     */
    public static function header(array $headers, string $name): ?string
    {
        $want = strtolower($name);
        $serverStyle = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        foreach ($headers as $key => $value) {
            $key = (string) $key;
            if (strtolower($key) !== $want && $key !== $serverStyle) {
                continue;
            }
            if (is_array($value)) {
                $first = $value[0] ?? null;

                return is_scalar($first) ? (string) $first : null;
            }

            return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }

    /**
     * An HTTP header date in one of the three formats RFC 7231 allows, as unix seconds; null for
     * anything else.
     *
     * `strtotime()` is not a substitute: it reads `-3` as a relative time and `now` as today, so a
     * garbage `Retry-After` or a mangled `Date` would become a real — and wildly wrong — instant.
     */
    public static function parseHttpDate(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['D, d M Y H:i:s T', 'l, d-M-y H:i:s T', 'D M j H:i:s Y'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('GMT'));
            if ($parsed !== false) {
                return $parsed->getTimestamp();
            }
        }

        return null;
    }

    /** Monotonic-enough wall clock in milliseconds, for timeouts and deadlines. */
    public static function nowMs(): float
    {
        return microtime(true) * 1000;
    }
}
