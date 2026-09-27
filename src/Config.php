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
 * Environment: `OBLODAI_PUBLIC_ID`, `OBLODAI_SECRET`, `OBLODAI_BASE_URL`, `OBLODAI_LOG`,
 * `OBLODAI_ALLOW_INSECURE`. (`OBLODAI_ADMIN_TOKEN` is deprecated and ignored.)
 */
final class Config implements JsonSerializable
{
    public const DEFAULT_BASE_URL = 'https://api.oblodai.com';

    /**
     * @param Secret|string|null $adminToken deprecated and ignored: the SDK never sends an admin
     *                                       token (operator-only routes are not supported)
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly ?Credentials $credentials = null,
        public readonly ?Logger $logger = null,
        #[\SensitiveParameter] Secret|string|null $adminToken = null,
    ) {
        unset($adminToken); // deprecated and ignored: never stored, never sent
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
    public static function resolve(#[\SensitiveParameter] array $options = [], ?array $env = null): self
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

        // Deprecated and ignored: the core accepts operator-only routes over the operator HMAC
        // channel only, which the SDK does not implement, and a raw admin token is never sent.
        $adminToken = $options['adminToken'] ?? $read('OBLODAI_ADMIN_TOKEN');
        // One warning per client configured with it.
        if ($adminToken !== null && $adminToken !== '' && $logger !== null) {
            $logger->warning(
                'adminToken / OBLODAI_ADMIN_TOKEN is deprecated and ignored: the SDK never sends an '
                    . 'admin token; operator-only routes are not supported, use the dashboard'
            );
        }

        return new self(
            baseUrl: $baseUrl,
            credentials: $publicId !== null && $secret !== null ? new Credentials($publicId, $secret) : null,
            logger: $logger,
        );
    }

    /**
     * https only — plain http (loopback included) only when explicitly permitted — and never
     * `user:password@`: credentials in the URL would ride into every log line and error naming it.
     */
    private static function assertBaseUrl(#[\SensitiveParameter] string $baseUrl, bool $allowInsecure): void
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            // Not echoed: an unparsable value may still carry a password.
            throw new ConfigException(ConfigException::BAD_CONFIG, 'baseUrl is not a valid URL', 'baseUrl');
        }
        if (isset($parts['user']) || isset($parts['pass']) || str_contains(explode('/', $baseUrl . '/', 4)[2] ?? '', '@')) {
            throw new ConfigException(
                ConfigException::BAD_CONFIG,
                'baseUrl must not contain user:password@ credentials',
                'baseUrl'
            );
        }
        if (strtolower($parts['scheme']) === 'https') {
            return;
        }
        if (strtolower($parts['scheme']) === 'http' && $allowInsecure) {
            return;
        }

        throw new ConfigException(
            ConfigException::BAD_CONFIG,
            sprintf(
                'baseUrl must use https (got %s://%s); set allowInsecureBaseUrl (OBLODAI_ALLOW_INSECURE=1) for a local core',
                $parts['scheme'],
                $parts['host']
            ),
            'baseUrl'
        );
    }
}
