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
    /** Opt-in: make an unknown closed-vocabulary value throw instead of decoding openly. */
    private static bool $strict = false;

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

    /**
     * A boolean field. A proxy or a lax serializer may hand over the string `"false"`, which PHP's
     * own cast turns into `true` — the one wrong answer that matters, since these fields gate
     * refunds and finality. Strings are read as the words they are; anything unrecognised falls back
     * to the documented default.
     *
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1', 'yes', 'on' => true,
                'false', '0', 'no', 'off', '' => false,
                default => $default,
            };
        }

        return $default;
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
     * A closed-vocabulary field, decoded openly: the result always carries the raw wire string and
     * carries the typed case only when this contract snapshot knows the value.
     *
     * The gateway adds statuses without asking; a webhook receiver that threw on the first unknown
     * one would answer 500 to an authentic delivery and have it redelivered for a day. Turn
     * {@see Wire::strict()} on in tests (never in production) to make drift loud instead.
     *
     * @template T of BackedEnum
     *
     * @param array<string, mixed> $data
     * @param class-string<T>      $enum
     * @param T|null               $default used when the field is absent or empty
     *
     * @return OpenEnum<T>
     */
    public static function enum(string $enum, array $data, string $key, ?BackedEnum $default = null): OpenEnum
    {
        $value = self::str($data, $key);
        if ($value === '' && $default instanceof $enum) {
            return OpenEnum::of($enum, (string) $default->value);
        }
        $decoded = OpenEnum::of($enum, $value);
        if (self::$strict && !$decoded->isKnown()) {
            throw new ContractException(sprintf(
                'the core sent "%s" for `%s`, which is outside the %s vocabulary of this contract '
                    . 'snapshot — upgrade the SDK (strict vocabulary mode is on)',
                $value,
                $key,
                (new ReflectionClass($enum))->getShortName()
            ));
        }

        return $decoded;
    }

    /**
     * Opt-in drift detector: with strict mode on, a closed-vocabulary value outside this snapshot
     * raises `ContractException` instead of decoding to an unknown {@see OpenEnum}. Meant for test
     * suites and staging; production receivers should stay permissive.
     */
    public static function strict(bool $on = true): void
    {
        self::$strict = $on;
    }

    public static function isStrict(): bool
    {
        return self::$strict;
    }
}
