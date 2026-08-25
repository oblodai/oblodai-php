<?php

declare(strict_types=1);

namespace Oblodai\Contract\Model;

use BackedEnum;
use Oblodai\Exception\ContractException;
use ReflectionClass;

/**
 * Reading the wire without lying about it. Every model decodes through these helpers, so a field
 * the core stopped sending (or started sending as another type) degrades to the documented default
 * instead of throwing deep inside a getter — and static analysis still sees exact types.
 */
final class Wire
{
    /** @param array<string, mixed> $data */
    public static function str(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Distinguishes "the core sent null" from "the core sent a value".
     *
     * @param array<string, mixed> $data
     */
    public static function nullableStr(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @param array<string, mixed> $data */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function float(array $data, string $key, float $default = 0.0): float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /** @param array<string, mixed> $data */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : (is_scalar($value) ? (bool) $value : $default);
    }

    /** @param array<string, mixed> $data */
    public static function nullableBool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * A nested object.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function obj(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A nested list of objects, ready to be mapped into models.
     *
     * @param  array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function rows(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * A nested list of objects, or null when the field is absent (an `info`-only block).
     *
     * @param  array<string, mixed> $data
     * @return list<array<string, mixed>>|null
     */
    public static function optionalRows(array $data, string $key): ?array
    {
        return array_key_exists($key, $data) && is_array($data[$key]) ? self::rows($data, $key) : null;
    }

    /**
     * A list of strings (CIDRs, codes, …).
     *
     * @param  array<string, mixed> $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }

    /**
     * A map of decimal amounts by asset (`earnings_by_asset`).
     *
     * @param  array<string, mixed> $data
     * @return array<string, string>
     */
    public static function stringMap(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $mapKey => $item) {
            if (is_scalar($item)) {
                $out[(string) $mapKey] = (string) $item;
            }
        }

        return $out;
    }

    /**
     * A list of ints (referral tiers in basis points).
     *
     * @param  array<string, mixed> $data
     * @return list<int>
     */
    public static function ints(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_numeric($item)) {
                $out[] = (int) $item;
            }
        }

        return $out;
    }

    /**
     * A closed vocabulary field. An unknown value is contract drift, not a runtime guess, so it
     * raises `ContractException` — loud in tests, and never a silently wrong branch in production.
     *
     * @template T of BackedEnum
     *
     * @param  array<string, mixed> $data
     * @param  class-string<T>      $enum
     * @return T
     */
    public static function enum(string $enum, array $data, string $key, ?BackedEnum $default = null): BackedEnum
    {
        $value = self::str($data, $key);
        $case = $enum::tryFrom($value);
        if ($case !== null) {
            return $case;
        }
        if ($default instanceof $enum) {
            return $default;
        }

        throw new ContractException(sprintf(
            'the core sent "%s" for `%s`, which is outside the %s vocabulary of this contract '
                . 'snapshot — upgrade the SDK',
            $value,
            $key,
            (new ReflectionClass($enum))->getShortName()
        ));
    }
}
