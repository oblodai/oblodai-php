<?php

declare(strict_types=1);

namespace Oblodai\Tests\Support;

use RuntimeException;

/**
 * The shared contract inputs: the route/enum/vector snapshot, the golden response bodies the core
 * recorded, the error envelopes and the signed webhook deliveries.
 */
final class Fixtures
{
    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/contract';
    }

    /** @return array<string, mixed> */
    public static function contract(): array
    {
        return self::json(self::dir() . '/contract.json');
    }

    /**
     * Every recorded route response, keyed by `METHOD /path`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        /** @var array<string, array<string, mixed>>|null $cache */
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $out = [];
        foreach ((array) glob(self::dir() . '/fixtures/*.json') as $path) {
            $fixture = self::json((string) $path);
            $route = $fixture['route'] ?? null;
            if (is_string($route)) {
                $out[$route] = $fixture;
            }
        }
        ksort($out);

        return $cache = $out;
    }

    /** @return array<string, mixed> */
    public static function get(string $route): array
    {
        $fixture = self::all()[$route] ?? null;
        if ($fixture === null) {
            throw new RuntimeException(sprintf('no fixture for %s', $route));
        }

        return $fixture;
    }

    /** The recorded success `result` for a route. */
    public static function result(string $route): mixed
    {
        $fixture = self::get($route);
        $status = $fixture['status'] ?? 0;
        if (!is_int($status) || $status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('fixture for %s is a refusal (%s)', $route, json_encode($status)));
        }
        $response = $fixture['response'] ?? [];

        return is_array($response) ? ($response['result'] ?? null) : null;
    }

    /**
     * The recorded success `result` as an object.
     *
     * @return array<string, mixed>
     */
    public static function resultArray(string $route): array
    {
        $result = self::result($route);
        if (!is_array($result)) {
            throw new RuntimeException(sprintf('fixture for %s has no object result', $route));
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Error envelope samples, keyed by error code.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function errors(): array
    {
        $out = [];
        foreach ((array) glob(self::dir() . '/errors/*.json') as $path) {
            $out[basename((string) $path, '.json')] = self::json((string) $path);
        }
        ksort($out);

        return $out;
    }

    /**
     * Real signed webhook deliveries: headers plus the exact bytes delivered.
     *
     * @return list<array<string, mixed>>
     */
    public static function webhookSamples(): array
    {
        $samples = json_decode((string) file_get_contents(self::dir() . '/webhook-samples.json'), true);
        $out = [];
        foreach (is_array($samples) ? $samples : [] as $sample) {
            if (is_array($sample)) {
                /** @var array<string, mixed> $sample */
                $out[] = $sample;
            }
        }

        return $out;
    }

    /** The webhook endpoint secret those samples were signed with. */
    public static function webhookSecret(): string
    {
        $rotated = self::resultArray('POST /v1/webhooks/rotate-secret');
        $secret = $rotated['secret'] ?? '';

        return is_string($secret) ? $secret : '';
    }

    /** @return array<string, mixed> */
    private static function json(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('%s does not hold a JSON object', $path));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
