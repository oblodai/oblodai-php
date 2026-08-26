<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Config;
use Oblodai\Contract\Model\ApiKeyPair;
use Oblodai\Contract\Model\BatchElement;
use Oblodai\Contract\Model\MerchantOnboarded;
use Oblodai\Contract\Model\PayoutLink;
use Oblodai\Contract\Model\WebhookEndpoint;
use Oblodai\Contract\Model\WebhookSecretRotated;
use Oblodai\Core\Credentials;
use Oblodai\Core\Secret;
use Oblodai\Log\ConsoleLogger;
use Oblodai\Log\Logger;
use Oblodai\Log\NullLogger;
use Oblodai\Log\RedactingLogger;
use Oblodai\Oblodai;
use Oblodai\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Secrets must not reach a log, a crash report or a serialized session by accident.
 *
 * A webhook secret and a claim token are shown once; whoever ends up with a copy can forge a
 * delivery or claim someone else's money. So every wholesale rendering of an object that holds one
 * is checked here — including `print_r` and `var_export`, which PHP does not let a class intercept
 * and which are therefore defeated by not storing the value on the object at all.
 */
final class RedactionTest extends TestCase
{
    private const SECRET = 'whsec_live_do_not_log_me';

    /** Everything PHP offers for turning an object into text, in one place. */
    private static function renderings(object $value): string
    {
        return implode("\n", [
            print_r($value, true),
            var_export($value, true),
            (string) json_encode($value),
            serialize($value),
            self::dump($value),
        ]);
    }

    private static function dump(object $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }

    public function testCredentialsNeverRenderTheirSecret(): void
    {
        $credentials = new Credentials('pk_live_1', self::SECRET);

        self::assertSame(self::SECRET, $credentials->secret(), 'the signer still gets the bytes');
        self::assertStringNotContainsString(self::SECRET, self::renderings($credentials));
        self::assertStringContainsString('pk_live_1', print_r($credentials, true));
    }

    public function testASecretRendersAsRedactedEverywhere(): void
    {
        $secret = new Secret(self::SECRET);

        self::assertSame(self::SECRET, $secret->reveal());
        self::assertStringNotContainsString(self::SECRET, self::renderings($secret));
        self::assertSame(Secret::redacted(), (string) $secret);
        self::assertSame('"' . Secret::redacted() . '"', json_encode($secret));
    }

    public function testTheClientAndItsConfigNeverRenderCredentialsOrTheAdminToken(): void
    {
        $client = new Oblodai(
            publicId: 'pk_live_1',
            secret: self::SECRET,
            payoutPublicId: 'pk_live_2',
            payoutSecret: 'payout_' . self::SECRET,
            adminToken: 'admin_' . self::SECRET,
            baseUrl: 'https://api.test',
            http: new FakeHttpClient(),
            env: [],
        );

        $rendered = self::renderings($client->config) . self::renderings($client->transport);
        self::assertStringNotContainsString(self::SECRET, $rendered);
        self::assertStringContainsString(
            'api.test',
            (string) json_encode($client->config),
            'the safe half of the config is still loggable'
        );
    }

    /** @return iterable<string, array{object}> */
    public static function secretBearingModels(): iterable
    {
        yield 'webhook endpoint' => [WebhookEndpoint::fromArray([
            'endpoint_id' => 'e1', 'url' => 'https://shop.example/hook', 'secret' => self::SECRET,
        ])];
        yield 'rotated secret' => [WebhookSecretRotated::fromArray([
            'endpoint_id' => 'e1', 'url' => 'https://shop.example/hook', 'secret' => self::SECRET,
            'previous_secret_valid_until' => '2026-01-02T00:00:00Z',
        ])];
        yield 'api key pair' => [ApiKeyPair::fromArray([
            'public_id' => 'pk', 'secret' => self::SECRET, 'kind' => 'payment',
        ])];
        yield 'payout link' => [PayoutLink::fromArray([
            'link_id' => 'l1', 'status' => 'funded', 'claim_token' => self::SECRET, 'passcode' => self::SECRET,
            'claim_url' => 'https://pay.test/claim/' . self::SECRET,
        ])];
        yield 'batch element carrying a payout link' => [BatchElement::fromArray([
            'idx' => 0, 'ok' => true,
            'result' => [
                'link_id' => 'l1', 'status' => 'funded', 'claim_token' => self::SECRET,
                'claim_url' => 'https://pay.test/claim/' . self::SECRET,
            ],
        ], static fn (array $raw): PayoutLink => PayoutLink::fromArray($raw))];
        yield 'merchant onboarded' => [MerchantOnboarded::fromArray([
            'merchant_id' => 'm1', 'project_id' => 'p1',
            'api_key' => ['public_id' => 'pk', 'secret' => self::SECRET, 'kind' => 'any'],
            'payment_key' => ['public_id' => 'pk', 'secret' => self::SECRET, 'kind' => 'payment'],
            'payout_key' => ['public_id' => 'wk', 'secret' => self::SECRET, 'kind' => 'payout'],
        ])];
    }

    /**
     * Models keep the secret readable as a property — that is what the caller asked the API for —
     * but every wholesale rendering masks it, including the copy sitting in the raw wire body.
     */
    #[DataProvider('secretBearingModels')]
    public function testASecretBearingModelMasksItselfInJsonDumpAndSerialize(object $model): void
    {
        $json = (string) json_encode($model);
        self::assertStringNotContainsString(self::SECRET, $json);
        self::assertStringContainsString('[redacted]', $json);
        self::assertStringNotContainsString(self::SECRET, self::dump($model));
        self::assertStringNotContainsString(self::SECRET, serialize($model));
    }

    /**
     * The claim URL does not read like a secret and is one: it embeds the cheque's `claim_token`,
     * so whoever copies it out of a log can claim the money.
     */
    public function testAClaimUrlIsRedactedBecauseItEmbedsTheClaimToken(): void
    {
        $url = 'https://pay.test/claim/' . self::SECRET;
        $link = PayoutLink::fromArray([
            'link_id' => 'l1', 'status' => 'funded', 'claim_token' => self::SECRET, 'claim_url' => $url,
        ]);

        self::assertSame($url, $link->claim_url, 'the property is what the caller asked the API for');
        self::assertSame($url, $link->toArray()['claim_url'] ?? null, 'toArray() is the escape hatch');
        // Same three renderings as every other secret-bearing model: `print_r`/`var_export` read
        // public properties directly and cannot be intercepted, which is why they are documented
        // as unsafe rather than asserted here.
        self::assertStringNotContainsString($url, (string) json_encode($link));
        self::assertStringNotContainsString($url, self::dump($link));
        self::assertStringNotContainsString($url, serialize($link));
        $decoded = json_decode((string) json_encode($link), true);
        self::assertIsArray($decoded);
        self::assertSame('[redacted]', $decoded['claim_url']);
        $raw = $decoded['raw'];
        self::assertIsArray($raw);
        self::assertSame('[redacted]', $raw['claim_url'], 'the copy in the wire body too');
        self::assertSame('l1', $decoded['link_id'], 'the safe half of the model is still loggable');
    }

    public function testTheSecretIsStillReadableWhereTheCallerNeedsIt(): void
    {
        $endpoint = WebhookEndpoint::fromArray([
            'endpoint_id' => 'e1', 'url' => 'https://shop.example/hook', 'secret' => self::SECRET,
        ]);

        self::assertSame(self::SECRET, $endpoint->secret);
        self::assertSame(self::SECRET, $endpoint->toArray()['secret'] ?? null);
    }

    /**
     * Redaction happens before a logger is called, not inside the SDK's own logger classes — so a
     * caller's own implementation is covered too.
     */
    public function testACallerInjectedLoggerNeverSeesASecretField(): void
    {
        $spy = new class () implements Logger {
            /** @var list<array<string, mixed>> */
            public array $seen = [];

            public function debug(string $message, array $fields = []): void
            {
                $this->seen[] = $fields;
            }

            public function info(string $message, array $fields = []): void
            {
                $this->seen[] = $fields;
            }

            public function warning(string $message, array $fields = []): void
            {
                $this->seen[] = $fields;
            }

            public function error(string $message, array $fields = []): void
            {
                $this->seen[] = $fields;
            }
        };

        RedactingLogger::wrap($spy)->debug('outgoing', [
            'route' => 'POST /v1/payout',
            'secret' => self::SECRET,
            'nested' => ['passcode' => '0451', 'amount' => '25'],
        ]);

        self::assertSame([[
            'route' => 'POST /v1/payout',
            'secret' => '[redacted]',
            'nested' => ['passcode' => '[redacted]', 'amount' => '25'],
        ]], $spy->seen);
    }

    /** An already-wrapped logger is not wrapped twice, and no logger stays no logger. */
    public function testWrappingIsIdempotentAndTolerantOfNull(): void
    {
        $wrapped = RedactingLogger::wrap(new NullLogger());
        self::assertInstanceOf(NullLogger::class, $wrapped);
        self::assertInstanceOf(NullLogger::class, RedactingLogger::wrap(null));

        $once = RedactingLogger::wrap(new ConsoleLogger('error'));
        self::assertSame($once, RedactingLogger::wrap($once));
    }

    public function testTheEnvironmentNeverTurnsAnEmptyVariableIntoACredential(): void
    {
        $config = Config::resolve([], ['OBLODAI_BASE_URL' => 'https://api.test', 'OBLODAI_SECRET' => '', 'OBLODAI_PUBLIC_ID' => '']);

        self::assertNull($config->credentials, 'OBLODAI_SECRET= must not configure a client that signs with an empty key');
    }
}
