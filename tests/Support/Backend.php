<?php

declare(strict_types=1);

namespace Oblodai\Tests\Support;

use RuntimeException;

/**
 * The backend checkout the SDK is generated from: `$OBLODAI_BACKEND`, else `../oblodai-backend`
 * next to this repository. Its `services/core/api/openapi.json` carries the signing vectors, and
 * `tools/sdkgen/conformance` the shared behaviour scenarios every SDK runs.
 */
final class Backend
{
    public static function root(): string
    {
        $configured = getenv('OBLODAI_BACKEND');

        return is_string($configured) && $configured !== ''
            ? rtrim($configured, '/')
            : dirname(__DIR__, 3) . '/oblodai-backend';
    }

    public static function spec(): string
    {
        return self::root() . '/services/core/api/openapi.json';
    }

    public static function conformance(): string
    {
        $explicit = getenv('SDKGEN_CONFORMANCE');

        return is_string($explicit) && $explicit !== '' ? $explicit : self::root() . '/tools/sdkgen/conformance';
    }

    /** Whether a backend was named explicitly — then a missing file is an error, not a skip. */
    public static function required(): bool
    {
        return getenv('OBLODAI_BACKEND') !== false || getenv('SDKGEN_CONFORMANCE') !== false;
    }

    /** @return array<string, mixed> */
    public static function json(string $path): array
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException(sprintf('cannot read %s', $path));
        }
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('%s is not a JSON object', $path));
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
