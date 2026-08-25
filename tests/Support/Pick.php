<?php

declare(strict_types=1);

namespace Oblodai\Tests\Support;

use RuntimeException;

/**
 * Walking into a recorded body without losing the types. A fixture is `array<string, mixed>`, so
 * `$result['items'][0]['result']` is a chain of `mixed`; `Pick::at($result, 'items', 0, 'result')`
 * does the same walk and hands back the object it found.
 */
final class Pick
{
    /**
     * @param  int|string           $keys path segments to follow
     * @return array<string, mixed> the object at that path
     */
    public static function at(mixed $value, int|string ...$keys): array
    {
        $walked = [];
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new RuntimeException(sprintf(
                    'nothing at %s%s in the recorded body',
                    implode('.', $walked),
                    $walked === [] ? (string) $key : '.' . $key
                ));
            }
            $walked[] = (string) $key;
            $value = $value[$key];
        }
        if (!is_array($value)) {
            throw new RuntimeException(sprintf('%s is not an object in the recorded body', implode('.', $walked)));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Like `at()`, but returns null when the path is absent — for the blocks a route only sometimes
     * renders (`file` on a finished document job, `refunds` on `payment/info`).
     *
     * @return array<string, mixed>|null
     */
    public static function optional(mixed $value, int|string ...$keys): ?array
    {
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        /** @var array<string, mixed>|null $value */
        return is_array($value) ? $value : null;
    }
}
