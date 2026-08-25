<?php

declare(strict_types=1);

namespace Oblodai\Contract\Request;

use BackedEnum;

/**
 * Turns a DTO's properties into the wire body: unset (null) fields vanish rather than becoming
 * explicit JSON nulls, enums become their backing strings, and nested DTOs are flattened too.
 */
trait NormalizesFields
{
    /**
     * @param  array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected static function normalize(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[$key] = self::normalizeValue($value);
        }

        return $out;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof RequestBody) {
            return $value->toArray();
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::normalizeValue($v), $value);
        }

        return $value;
    }
}
