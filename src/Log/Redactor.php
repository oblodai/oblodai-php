<?php

declare(strict_types=1);

namespace Oblodai\Log;

/** Replaces the values of sensitive-looking keys, recursively, without touching the original. */
final class Redactor
{
    private const SENSITIVE = '/secret|signature|passcode|token|authorization|password/i';

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
