<?php

declare(strict_types=1);

namespace Oblodai;

use Oblodai\Core\Credentials;
use Oblodai\Exception\ConfigException;
use Oblodai\Log\ConsoleLogger;
use Oblodai\Log\Logger;

/**
 * Client configuration: explicit options merged with the environment, validated up front so a
 * misconfiguration fails at construction rather than on the first payout.
 *
 * Environment: `OBLODAI_PUBLIC_ID`, `OBLODAI_SECRET`, `OBLODAI_PAYOUT_PUBLIC_ID`,
 * `OBLODAI_PAYOUT_SECRET`, `OBLODAI_BASE_URL`, `OBLODAI_ADMIN_TOKEN`, `OBLODAI_ALLOW_INSECURE`,
 * `OBLODAI_LOG`.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.oblodai.com';

    public function __construct(
        public readonly string $baseUrl,
        public readonly ?Credentials $credentials = null,
        public readonly ?Credentials $payoutCredentials = null,
        public readonly ?Logger $logger = null,
        public readonly ?string $adminToken = null,
    ) {
    }

    /**
     * @param array{publicId?: ?string, secret?: ?string, payoutPublicId?: ?string, payoutSecret?: ?string, baseUrl?: ?string, adminToken?: ?string, logger?: ?Logger, allowInsecureBaseUrl?: ?bool} $options
     * @param array<string, string>|null $env null reads the process environment
     */
    public static function resolve(array $options = [], ?array $env = null): self
    {
        $read = static function (string $name) use ($env): ?string {
            if ($env !== null) {
                return $env[$name] ?? null;
            }
            $value = getenv($name);

            return $value === false || $value === '' ? null : $value;
        };

        $baseUrl = rtrim($options['baseUrl'] ?? $read('OBLODAI_BASE_URL') ?? self::DEFAULT_BASE_URL, '/');
        self::assertBaseUrl($baseUrl, $options['allowInsecureBaseUrl'] ?? ($read('OBLODAI_ALLOW_INSECURE') === '1'));

        $publicId = $options['publicId'] ?? $read('OBLODAI_PUBLIC_ID');
        $secret = $options['secret'] ?? $read('OBLODAI_SECRET');
        if (($publicId !== null) !== ($secret !== null)) {
            throw new ConfigException(
                ConfigException::BAD_CONFIG,
                'publicId and secret must be provided together (or set both OBLODAI_PUBLIC_ID and OBLODAI_SECRET)'
            );
        }
        $payoutPublicId = $options['payoutPublicId'] ?? $read('OBLODAI_PAYOUT_PUBLIC_ID');
        $payoutSecret = $options['payoutSecret'] ?? $read('OBLODAI_PAYOUT_SECRET');
        if (($payoutPublicId !== null) !== ($payoutSecret !== null)) {
            throw new ConfigException(
                ConfigException::BAD_CONFIG,
                'payoutPublicId and payoutSecret must be provided together'
            );
        }

        $logger = $options['logger'] ?? null;
        $level = strtolower((string) $read('OBLODAI_LOG'));
        if ($logger === null && in_array($level, ['debug', 'info', 'warn', 'error'], true)) {
            $logger = new ConsoleLogger($level);
        }

        return new self(
            baseUrl: $baseUrl,
            credentials: $publicId !== null && $secret !== null ? new Credentials($publicId, $secret) : null,
            payoutCredentials: $payoutPublicId !== null && $payoutSecret !== null
                ? new Credentials($payoutPublicId, $payoutSecret)
                : null,
            logger: $logger,
            adminToken: $options['adminToken'] ?? $read('OBLODAI_ADMIN_TOKEN'),
        );
    }

    /** Plain http is only ever allowed against a loopback core, or when explicitly permitted. */
    private static function assertBaseUrl(string $baseUrl, bool $allowInsecure): void
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigException(
                ConfigException::BAD_CONFIG,
                sprintf('baseUrl is not a valid URL: %s', $baseUrl),
                'baseUrl'
            );
        }
        if ($parts['scheme'] === 'https') {
            return;
        }
        $host = strtolower($parts['host']);
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($parts['scheme'] === 'http' && ($allowInsecure || $local)) {
            return;
        }

        throw new ConfigException(
            ConfigException::BAD_CONFIG,
            sprintf(
                'baseUrl must use https (got %s://%s); set allowInsecureBaseUrl for a local core',
                $parts['scheme'],
                $parts['host']
            ),
            'baseUrl'
        );
    }
}
