<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Config;
use Oblodai\Exception\ConfigException;
use Oblodai\Log\ConsoleLogger;
use PHPUnit\Framework\TestCase;

/** Ports test/unit/config.test.ts against Config::resolve(). */
final class ConfigTest extends TestCase
{
    public function testReadsCredentialsAndBaseUrlFromTheEnvironment(): void
    {
        $config = Config::resolve([], [
            'OBLODAI_PUBLIC_ID' => 'pk',
            'OBLODAI_SECRET' => 's',
            'OBLODAI_BASE_URL' => 'https://x.test/',
        ]);

        self::assertNotNull($config->credentials);
        self::assertSame('pk', $config->credentials->publicId);
        self::assertSame('s', $config->credentials->secret());
        self::assertSame('https://x.test', $config->baseUrl);
    }

    public function testRefusesPlainHttpUnlessExplicitlyAllowedLoopbackIncluded(): void
    {
        foreach (['http://api.oblodai.com', 'http://localhost:8093', 'http://127.0.0.1:8093', 'http://[::1]:8093'] as $url) {
            try {
                Config::resolve(['baseUrl' => $url], []);
                self::fail('expected a ConfigException for ' . $url);
            } catch (ConfigException $e) {
                self::assertMatchesRegularExpression('/https/', $e->getMessage());
            }
        }

        self::assertSame(
            'http://localhost:8093',
            Config::resolve(['baseUrl' => 'http://localhost:8093', 'allowInsecureBaseUrl' => true], [])->baseUrl
        );
        self::assertSame(
            'http://10.0.0.1',
            Config::resolve(['baseUrl' => 'http://10.0.0.1'], ['OBLODAI_ALLOW_INSECURE' => '1'])->baseUrl
        );
        self::assertSame(
            'http://[::1]:8093',
            Config::resolve(['baseUrl' => 'http://[::1]:8093', 'allowInsecureBaseUrl' => true], [])->baseUrl
        );
    }

    public function testRefusesUserinfoInTheBaseUrlWithoutEchoingIt(): void
    {
        foreach (['https://user:hunter2@api.test', 'https://hunter2@api.test'] as $url) {
            try {
                Config::resolve(['baseUrl' => $url], []);
                self::fail('expected a ConfigException for ' . $url);
            } catch (ConfigException $e) {
                self::assertStringContainsString('credentials', $e->getMessage());
                self::assertStringNotContainsString('hunter2', (string) $e);
            }
        }
    }

    public function testRefusesHalfAKeyPair(): void
    {
        try {
            Config::resolve(['publicId' => 'pk'], []);
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertMatchesRegularExpression('/together/', $e->getMessage());
        }

        try {
            Config::resolve([], ['OBLODAI_SECRET' => 's']);
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertMatchesRegularExpression('/together/', $e->getMessage());
        }
    }

    public function testObladaiLogDebugPicksAConsoleLogger(): void
    {
        $config = Config::resolve([], ['OBLODAI_LOG' => 'debug']);

        self::assertInstanceOf(ConsoleLogger::class, $config->logger);
    }

    public function testObladaiLogUnsetPicksNoLogger(): void
    {
        $config = Config::resolve([], []);

        self::assertNull($config->logger);
    }
}
