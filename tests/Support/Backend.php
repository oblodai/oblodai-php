<?php

declare(strict_types=1);

namespace Oblodai\Tests\Support;

use RuntimeException;

/**
 * The backend checkout the SDK is generated from: `$OBLODAI_BACKEND`, else `../oblodai-backend`
 * next to this repository. Its `services/core/api/openapi.json` carries the signing vectors, and
 * `tools/sdkgen/conformance` the shared behaviour scenarios every SDK runs. Without a backend (CI)
 * the conformance suite runs against the vendored snapshot in `contract/` (`make contract`).
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
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        $fromBackend = self::root() . '/tools/sdkgen/conformance';

        return getenv('OBLODAI_BACKEND') !== false || is_dir($fromBackend) ? $fromBackend : self::vendored() . '/conformance';
    }

    /** The vendored contract snapshot (`scripts/sync-contract.php`). */
    public static function vendored(): string
    {
        return dirname(__DIR__, 2) . '/contract';
    }

    /**
     * The spec a suite names (relative to the suite directory); the vendored snapshot carries only
     * its `x-oblodai-signing` block, which is all the suites point into.
     *
     * @return array<string, mixed>
     */
    public static function suiteSpec(string $relative): array
    {
        $path = self::conformance() . '/' . $relative;

        return self::json(is_file($path) ? $path : self::vendored() . '/signing.json');
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
