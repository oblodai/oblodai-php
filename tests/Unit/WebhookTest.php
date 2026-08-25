<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Core\Signer;
use Oblodai\Exception\SignatureException;
use Oblodai\Tests\Support\Fixtures;
use Oblodai\Webhook\Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ports test/unit/webhooks.test.ts against Webhook\Verifier. */
final class WebhookTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function webhookSamples(): iterable
    {
        foreach (Fixtures::webhookSamples() as $i => $sample) {
            yield 'sample #' . $i => [$sample];
        }
    }

    /**
     * Real fixture values only ever hold string headers; validate that rather than trusting a
     * generic `array<string, mixed>` fixture shape.
     *
     * @return array<string, string>
     */
    private static function asHeaderMap(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('malformed webhook sample: headers is not an array');
        }
        $out = [];
        foreach ($value as $key => $header) {
            if (!is_string($header)) {
                self::fail(sprintf('malformed webhook sample: header "%s" is not a string', (string) $key));
            }
            $out[(string) $key] = $header;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function asObjectMap(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('malformed webhook sample: body is not an array');
        }
        $out = [];
        foreach ($value as $key => $field) {
            $out[(string) $key] = $field;
        }

        return $out;
    }

    /** @param array<string, mixed> $sample */
    #[DataProvider('webhookSamples')]
    public function testVerifiesARealRecordedDelivery(array $sample): void
    {
        $headers = self::asHeaderMap($sample['headers'] ?? null);
        $body = self::asObjectMap($sample['body'] ?? null);
        $rawValue = $sample['raw'] ?? null;
        // The recorder keeps the exact delivered bytes; only fall back to a re-encode when absent.
        $raw = is_string($rawValue) ? $rawValue : (string) json_encode($body);
        $ts = (int) $headers['X-Webhook-Timestamp'];
        $secret = Fixtures::webhookSecret();

        $delivery = Verifier::verify($raw, $headers, $secret, now: $ts);

        self::assertSame($body['uuid'], $delivery->event->uuid());
        self::assertSame($headers['X-Webhook-Id'], $delivery->id);
        self::assertSame($headers['X-Webhook-Event'], $delivery->eventType);
        self::assertSame($body['type'], $delivery->event->type());
        // sequence() is a native `int` return type on WebhookEvent — PHP's typing already
        // guarantees what the JS port checks at runtime with `typeof … === "number"`.
        self::assertMatchesRegularExpression('/^(invoice|payout|wallet)\./', $headers['X-Webhook-Event']);

        try {
            Verifier::verify($raw, $headers, 'some-other-secret', 'another', now: $ts);
            self::fail('expected a SignatureException');
        } catch (SignatureException $e) {
            self::assertMatchesRegularExpression('/does not match/', $e->getMessage());
        }
    }

    private const TS = 1_755_600_000;

    private static function body(): string
    {
        return (string) json_encode([
            'type' => 'payment',
            'uuid' => 'u1',
            'order_id' => 'o',
            'status' => 'paid',
            'is_final' => true,
            'sequence' => 7,
            'event_at' => '2026-01-01T00:00:00Z',
        ]);
    }

    /**
     * @param  array<string, string> $overrides
     * @return array<string, string>
     */
    private static function headers(array $overrides = []): array
    {
        return array_merge([
            'X-Webhook-Timestamp' => (string) self::TS,
            'x-webhook-signature' => Signer::signWebhook('whsec', self::TS, self::body()),
        ], $overrides);
    }

    public function testAcceptsAValidSignatureWithCaseInsensitiveHeaders(): void
    {
        $event = Verifier::verify(self::body(), self::headers(), 'whsec', now: self::TS);

        self::assertSame('payment', $event->event->type());
    }

    public function testRejectsAWrongSecretATamperedBodyAndAMissingHeader(): void
    {
        try {
            Verifier::verify(self::body(), self::headers(), 'other', now: self::TS);
            self::fail('expected a SignatureException');
        } catch (SignatureException) {
        }

        try {
            Verifier::verify(str_replace('paid', 'paid_over', self::body()), self::headers(), 'whsec', now: self::TS);
            self::fail('expected a SignatureException');
        } catch (SignatureException $e) {
            self::assertMatchesRegularExpression('/does not match/', $e->getMessage());
        }

        try {
            Verifier::verify(self::body(), ['x-webhook-signature' => 'aa'], 'whsec');
            self::fail('expected a SignatureException');
        } catch (SignatureException $e) {
            self::assertMatchesRegularExpression('/missing/', $e->getMessage());
        }
    }

    public function testRejectsStaleDeliveriesUnlessToleranceIsDisabled(): void
    {
        try {
            Verifier::verify(self::body(), self::headers(), 'whsec', now: self::TS + 600);
            self::fail('expected a SignatureException');
        } catch (SignatureException $e) {
            self::assertMatchesRegularExpression('/outside/', $e->getMessage());
        }

        $event = Verifier::verify(self::body(), self::headers(), 'whsec', toleranceSec: 0, now: self::TS + 600);
        self::assertSame('u1', $event->event->uuid());
    }

    public function testVerifiesDuringASecretRotationViaThePrevHeaderOrThePreviousSecretOption(): void
    {
        $rotated = self::headers([
            'x-webhook-signature' => Signer::signWebhook('new', self::TS, self::body()),
            'x-webhook-signature-prev' => Signer::signWebhook('old', self::TS, self::body()),
        ]);

        // Not yet swapped: the stored secret is still "old", verified via the Prev header.
        self::assertSame('u1', Verifier::verify(self::body(), $rotated, 'old', now: self::TS)->event->uuid());
        // Swapped: the stored secret is "new", verified via the main header.
        self::assertSame('u1', Verifier::verify(self::body(), $rotated, 'new', now: self::TS)->event->uuid());
        // Or via the explicit previousSecret option.
        self::assertSame(
            'u1',
            Verifier::verify(self::body(), $rotated, 'unrelated', previousSecret: 'old', now: self::TS)->event->uuid()
        );
    }

    public function testParsesTheDiscriminatedUnionAndDetectsStaleSequences(): void
    {
        $event = Verifier::parse(self::body());

        self::assertSame('payment', $event->type());
        self::assertTrue(Verifier::isStale($event, 7));
        self::assertFalse(Verifier::isStale($event, 6));
        self::assertFalse(Verifier::isStale($event, null));
    }

    public function testRejectsAnUnknownEventType(): void
    {
        try {
            Verifier::parse('{"type":"alien","uuid":"x"}');
            self::fail('expected a SignatureException');
        } catch (SignatureException $e) {
            self::assertMatchesRegularExpression('/unknown event type/', $e->getMessage());
        }
    }
}
