<?php

declare(strict_types=1);

namespace Oblodai;

use JsonSerializable;
use Oblodai\Core\Credentials;
use Oblodai\Core\Secret;
use Oblodai\Exception\ConfigException;
use Oblodai\Log\ConsoleLogger;
use Oblodai\Log\Logger;

/**
 * Client configuration: explicit options merged with the environment, validated up front so a
 * misconfiguration fails at construction rather than on the first payout.
 *
 * Environment: `OBLODAI_PUBLIC_ID`, `OBLODAI_SECRET`, `OBLODAI_ADMIN_TOKEN`, `OBLODAI_BASE_URL`,
 * `OBLODAI_LOG`, `OBLODAI_ALLOW_INSECURE`.
 */
final class Config implements JsonSerializable
{
    public const DEFAULT_BASE_URL = 'https://api.oblodai.com';

    /** Admin token of a self-hosted gateway; sent as `X-Admin-Token` on `onboard` routes only. */
    public readonly ?Secret $adminToken;

    public function __construct(
        public readonly string $baseUrl,
        public readonly ?Credentials $credentials = null,
        public readonly ?Logger $logger = null,
        Secret|string|null $adminToken = null,
    ) {
        $this->adminToken = $adminToken === null || $adminToken === ''
            ? null
            : ($adminToken instanceof Secret ? $adminToken : new Secret($adminToken));
    }

    /**
     * Everything about the client that is safe to log. Secrets are not part of it — neither here
     * nor through the credentials, which redact themselves.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'publicId' => $this->credentials?->publicId,
            'adminToken' => $this->adminToken === null ? null : Secret::REDACTED,
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * @param array{publicId?: ?string, secret?: ?string, baseUrl?: ?string, adminToken?: ?string, logger?: ?Logger, allowInsecureBaseUrl?: ?bool} $options
     * @param array<string, string>|null $env null reads the process environment
     */
    public static function resolve(array $options = [], ?array $env = null): self
    {
        // An empty value means "not set" whether it came from the real environment or from an
        // injected map — otherwise `OBLODAI_SECRET=` would configure a client that signs with ''.
        $read = static function (string $name) use ($env): ?string {
            $value = $env !== null ? ($env[$name] ?? null) : getenv($name);

            return $value === false || $value === null || $value === '' ? null : $value;
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

        $logger = $options['logger'] ?? null;
        $level = strtolower((string) $read('OBLODAI_LOG'));
        if ($logger === null && in_array($level, ['debug', 'info', 'warn', 'error'], true)) {
            $logger = new ConsoleLogger($level);
        }

        return new self(
            baseUrl: $baseUrl,
            credentials: $publicId !== null && $secret !== null ? new Credentials($publicId, $secret) : null,
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
