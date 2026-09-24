<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Core\Signer;
use Oblodai\Exception\SignatureException;
use Oblodai\Exception\WebhookPayloadException;
use Oblodai\Tests\Support\Samples;
use Oblodai\Webhook\Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ports test/unit/webhooks.test.ts against Webhook\Verifier. */
final class WebhookTest extends TestCase
{
    /** A full delivery of each modelled kind verifies and reads into its generated model. */
    #[DataProvider('modelledKinds')]
    public function testAFullDeliveryReadsIntoTheModelOfItsType(string $type, string $class): void
    {
        /** @var class-string $class */
        $body = Samples::of($class, ['type' => $type, 'sequence' => 3]);
        $raw = (string) json_encode($body);
        $headers = [
            'X-Webhook-Timestamp' => (string) self::TS,
            'X-Webhook-Signature' => Signer::signWebhook('whsec', self::TS, $raw),
            'X-Webhook-Id' => 'd-1',
            'X-Webhook-Event-Id' => 'e-1',
            'X-Webhook-Event' => $type . '.x',
            'X-Webhook-Event-Time' => '1755600001',
        ];

        $delivery = Verifier::verify($raw, $headers, 'whsec', now: self::TS);
        $model = Verifier::model($delivery->event);

        self::assertInstanceOf($class, $model);
        self::assertTrue(Verifier::isKnownEvent($delivery->event));
        self::assertSame('d-1', $delivery->id);
        self::assertSame('e-1', $delivery->eventId);
        self::assertSame($type . '.x', $delivery->eventType);
        self::assertSame(1755600001, $delivery->eventTime);
        self::assertSame(self::TS, $delivery->sentAt);
    }

    /** @return iterable<string, array{string, string}> */
    public static function modelledKinds(): iterable
    {
        foreach (Verifier::EVENT_MODELS as $type => $class) {
            yield $type => [$type, $class];
        }
    }

    public function testAKnownTypeWithoutItsDocumentedFieldsIsABadPayload(): void
    {
        $this->expectException(WebhookPayloadException::class);
        Verifier::model(['type' => 'payment', 'uuid' => 'u1']);
    }

    public function testAnUnknownTypeHasNoModel(): void
    {
        self::assertNull(Verifier::model(['type' => 'refund_v2', 'uuid' => 'u1']));
        self::assertFalse(Verifier::isKnownEvent(['type' => 'refund_v2']));
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

        self::assertSame('payment', $event->event['type']);
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
        self::assertSame('u1', $event->event['uuid']);
    }

    public function testVerifiesDuringASecretRotationViaThePrevHeaderOrThePreviousSecretOption(): void
    {
        $rotated = self::headers([
            'x-webhook-signature' => Signer::signWebhook('new', self::TS, self::body()),
            'x-webhook-signature-prev' => Signer::signWebhook('old', self::TS, self::body()),
        ]);

        // Not yet swapped: the stored secret is still "old", verified via the Prev header.
        self::assertSame('u1', Verifier::verify(self::body(), $rotated, 'old', now: self::TS)->event['uuid']);
        // Swapped: the stored secret is "new", verified via the main header.
        self::assertSame('u1', Verifier::verify(self::body(), $rotated, 'new', now: self::TS)->event['uuid']);
        // Or via the explicit previousSecret option.
        self::assertSame(
            'u1',
            Verifier::verify(self::body(), $rotated, 'unrelated', previousSecret: 'old', now: self::TS)->event['uuid']
        );
    }

    public function testParsesTheDiscriminatedUnionAndDetectsStaleSequences(): void
    {
        $event = Verifier::parse(self::body());

        self::assertSame('payment', $event['type']);
        self::assertTrue(Verifier::isStale($event, 7));
        self::assertFalse(Verifier::isStale($event, 6));
        self::assertFalse(Verifier::isStale($event, null));
    }

    private static function testBody(): string
    {
        return (string) json_encode([
            'type' => 'payment',
            'uuid' => 'u1',
            'order_id' => 'o',
            'status' => 'paid',
            'is_final' => true,
            'sequence' => 7,
            'event_at' => '2026-01-01T00:00:00Z',
            'test' => true,
        ]);
    }

    public function testFlagsRehearsalDeliveriesFromEitherTheBodyOrTheHeader(): void
    {
        // A live delivery: neither the body flag nor the header.
        $live = Verifier::verify(self::body(), self::headers(), 'whsec', now: self::TS);
        self::assertFalse($live->isTest);
                self::assertFalse(Verifier::isTestEvent($live->event));

        // A rehearsal delivery: the flag rides inside the signed body.
        $raw = self::testBody();
        $headers = [
            'X-Webhook-Timestamp' => (string) self::TS,
            'x-webhook-signature' => Signer::signWebhook('whsec', self::TS, $raw),
            'X-Webhook-Test' => 'true',
        ];
        $rehearsal = Verifier::verify($raw, $headers, 'whsec', now: self::TS);
        self::assertTrue($rehearsal->isTest);
                self::assertTrue(Verifier::isTestEvent($rehearsal->event));

        // The header alone is enough, even if a body somehow omits the flag.
        unset($headers['x-webhook-signature']);
        $headers['x-webhook-signature'] = Signer::signWebhook('whsec', self::TS, self::body());
        self::assertTrue(Verifier::verify(self::body(), $headers, 'whsec', now: self::TS)->isTest);
    }
}
