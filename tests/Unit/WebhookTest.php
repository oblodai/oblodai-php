<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Core\Signer;
use Oblodai\Exception\SignatureException;
use Oblodai\Exception\WebhookPayloadException;
use Oblodai\Generated\Facts;
use Oblodai\Generated\Model\ConversionWebhook;
use Oblodai\Generated\Signing;
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
            Signing::HEADER_WEBHOOK_TIMESTAMP => (string) self::TS,
            Signing::HEADER_WEBHOOK_SIGNATURE => Signer::signWebhook('whsec', self::TS, $raw),
            Signing::HEADER_WEBHOOK_ID => 'd-1',
            Signing::HEADER_WEBHOOK_EVENT_ID => 'e-1',
            Signing::HEADER_WEBHOOK_EVENT => $type . '.x',
            Signing::HEADER_WEBHOOK_EVENT_TIME => '1755600001',
        ];

        $delivery = Verifier::verify($raw, $headers, 'whsec', now: self::TS);
        $model = Verifier::model($delivery->event);

        self::assertInstanceOf($class, $model);
        self::assertTrue(Verifier::isKnownEvent($delivery->event));
        // The id/event headers are not signed: reported under `unverified` only.
        self::assertSame('d-1', $delivery->unverified->deliveryId);
        self::assertSame('e-1', $delivery->unverified->eventId);
        self::assertSame($type . '.x', $delivery->unverified->eventType);
        self::assertSame(1755600001, $delivery->unverified->eventTime);
        self::assertSame(self::TS, $delivery->sentAt);
        // The dedupe key comes from the signed body.
        self::assertSame(sprintf('%s:%s:3', $type, Verifier::objectId($delivery->event)), $delivery->eventKey);
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

    /** The kinds are the contract's webhooks: every event name maps to a modelled kind. */
    public function testTheModelledKindsAreTheContractsWebhooks(): void
    {
        self::assertSame(Facts::WEBHOOK_MODELS, Verifier::EVENT_MODELS);
        self::assertSame(Facts::WEBHOOK_KINDS, array_keys(Facts::WEBHOOK_MODELS));
        self::assertContains('conversion', Facts::WEBHOOK_KINDS);
        self::assertSame('conversion', Facts::WEBHOOK_EVENTS['conversion.completed']);
        self::assertSame('payment', Facts::WEBHOOK_EVENTS['invoice.paid']);
        foreach (Facts::WEBHOOK_EVENTS as $event => $kind) {
            self::assertArrayHasKey($kind, Verifier::EVENT_MODELS, $event);
        }
        $conversion = ['type' => 'conversion'] + Samples::of(ConversionWebhook::class, ['type' => 'conversion']);
        self::assertTrue(Verifier::isKnownEvent($conversion));
        self::assertInstanceOf(ConversionWebhook::class, Verifier::model($conversion));
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
            Signing::HEADER_WEBHOOK_TIMESTAMP => (string) self::TS,
            strtolower(Signing::HEADER_WEBHOOK_SIGNATURE) => Signer::signWebhook('whsec', self::TS, self::body()),
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
            Verifier::verify(self::body(), [strtolower(Signing::HEADER_WEBHOOK_SIGNATURE) => 'aa'], 'whsec');
            self::fail('expected a SignatureException');
        } catch (SignatureException $e) {
            self::assertMatchesRegularExpression('/missing/', $e->getMessage());
        }
    }

    public function testRejectsStaleDeliveriesUnlessToleranceIsDisabled(): void
    {
        try {
            Verifier::verify(self::body(), self::headers(), 'whsec', now: self::TS + 2 * Signing::SKEW_SECONDS);
            self::fail('expected a SignatureException');
        } catch (SignatureException $e) {
            self::assertMatchesRegularExpression('/outside/', $e->getMessage());
        }

        $event = Verifier::verify(self::body(), self::headers(), 'whsec', toleranceSec: 0, now: self::TS + 2 * Signing::SKEW_SECONDS);
        self::assertSame('u1', $event->event['uuid']);
    }

    public function testVerifiesDuringASecretRotationViaThePrevHeaderOrThePreviousSecretOption(): void
    {
        $rotated = self::headers([
            strtolower(Signing::HEADER_WEBHOOK_SIGNATURE) => Signer::signWebhook('new', self::TS, self::body()),
            strtolower(Signing::HEADER_WEBHOOK_SIGNATURE_PREV) => Signer::signWebhook('old', self::TS, self::body()),
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

    public function testFlagsRehearsalDeliveriesFromTheSignedBodyOnly(): void
    {
        // A live delivery: neither the body flag nor the header.
        $live = Verifier::verify(self::body(), self::headers(), 'whsec', now: self::TS);
        self::assertFalse($live->isTest);
        self::assertFalse(Verifier::isTestEvent($live->event));

        // A rehearsal delivery: the flag rides inside the signed body.
        $raw = self::testBody();
        $headers = [
            Signing::HEADER_WEBHOOK_TIMESTAMP => (string) self::TS,
            strtolower(Signing::HEADER_WEBHOOK_SIGNATURE) => Signer::signWebhook('whsec', self::TS, $raw),
            Signing::HEADER_WEBHOOK_TEST => 'true',
        ];
        $rehearsal = Verifier::verify($raw, $headers, 'whsec', now: self::TS);
        self::assertTrue($rehearsal->isTest);
        self::assertTrue(Verifier::isTestEvent($rehearsal->event));

        // The header is NOT signed: added to a captured live delivery it must not get a real payment
        // dropped as a rehearsal. It is reported under `unverified` only.
        unset($headers[strtolower(Signing::HEADER_WEBHOOK_SIGNATURE)]);
        $headers[strtolower(Signing::HEADER_WEBHOOK_SIGNATURE)] = Signer::signWebhook('whsec', self::TS, self::body());
        $forged = Verifier::verify(self::body(), $headers, 'whsec', now: self::TS);
        self::assertFalse($forged->isTest);
        self::assertTrue($forged->unverified->test);

        // …and a signed test body stays a test whatever the header says.
        $headers = [
            Signing::HEADER_WEBHOOK_TIMESTAMP => (string) self::TS,
            Signing::HEADER_WEBHOOK_SIGNATURE => Signer::signWebhook('whsec', self::TS, $raw),
            Signing::HEADER_WEBHOOK_TEST => 'false',
        ];
        self::assertTrue(Verifier::verify($raw, $headers, 'whsec', now: self::TS)->isTest);
    }

    public function testTheDedupeKeyIgnoresAReplayedEventIdHeader(): void
    {
        $raw = (string) json_encode(Samples::of(Verifier::EVENT_MODELS['payment'], ['type' => 'payment', 'uuid' => 'u-1', 'sequence' => 4]));
        $headers = static fn (string $eventId): array => [
            Signing::HEADER_WEBHOOK_TIMESTAMP => (string) self::TS,
            Signing::HEADER_WEBHOOK_SIGNATURE => Signer::signWebhook('whsec', self::TS, $raw),
            Signing::HEADER_WEBHOOK_EVENT_ID => $eventId,
        ];
        $original = Verifier::verify($raw, $headers('e-1'), 'whsec', now: self::TS);
        $replayed = Verifier::verify($raw, $headers('e-forged'), 'whsec', now: self::TS);

        self::assertSame('payment:u-1:4', $original->eventKey);
        self::assertSame($original->eventKey, $replayed->eventKey);
        self::assertNull(Verifier::eventKey(['type' => 'payment', 'uuid' => 'u-1']), 'no sequence, no key');
        self::assertNull(Verifier::eventKey(['type' => 'teleport', 'uuid' => 'u-1', 'sequence' => 1]), 'unknown kind');
    }

    public function testTheDedupeKeyIsTheSignedEventIdAndSurvivesAResend(): void
    {
        $eventId = '7f1c5a2e-9b1d-5c3e-8a4f-0d2b6e9c1a33';
        $deliver = self::deliverPayment(...);
        $original = $deliver(['sequence' => 6, Signing::WEBHOOK_EVENT_ID_FIELD => $eventId], $eventId);
        // A resend: the same event_id, a higher sequence, a forged header — the same key.
        $resend = $deliver(['sequence' => 9, Signing::WEBHOOK_EVENT_ID_FIELD => $eventId], 'e-forged');

        self::assertSame($eventId, $original->eventKey);
        self::assertSame($original->eventKey, $resend->eventKey);
        self::assertSame('e-forged', $resend->unverified->eventId);
        self::assertSame('other-state', $deliver(['sequence' => 10, Signing::WEBHOOK_EVENT_ID_FIELD => 'other-state'], $eventId)->eventKey);
        // An older core without event_id: fallback type:id:sequence from the body, never the header.
        self::assertSame('payment:u-1:6', $deliver(['sequence' => 6], $eventId)->eventKey);
        // The model keeps the optional event_id.
        $model = Verifier::model($original->event);
        self::assertInstanceOf(Verifier::EVENT_MODELS['payment'], $model);
        self::assertSame($eventId, $model->event_id ?? null);
    }

    /** @param array<string, mixed> $fields */
    private static function deliverPayment(array $fields, string $headerEventId): \Oblodai\Webhook\Delivery
    {
        $raw = (string) json_encode(Samples::of(Verifier::EVENT_MODELS['payment'], array_merge(['type' => 'payment', 'uuid' => 'u-1'], $fields)));

        return Verifier::verify($raw, [
            Signing::HEADER_WEBHOOK_TIMESTAMP => (string) self::TS,
            Signing::HEADER_WEBHOOK_SIGNATURE => Signer::signWebhook('whsec', self::TS, $raw),
            Signing::HEADER_WEBHOOK_EVENT_ID => $headerEventId,
        ], 'whsec', now: self::TS);
    }

    public function testObjectIdIsTheFieldTheContractNamesForTheKind(): void
    {
        self::assertSame(Facts::WEBHOOK_KINDS, array_keys(Facts::WEBHOOK_ID_FIELDS));
        foreach (Facts::WEBHOOK_ID_FIELDS as $kind => $field) {
            self::assertSame('obj-1', Verifier::objectId(Verifier::parse((string) json_encode(['type' => $kind, $field => 'obj-1']))), $kind);
        }
        // A conversion names its object by `id`; a `uuid` beside it is not the object's id.
        self::assertSame('c1', Verifier::objectId(['type' => 'conversion', 'id' => 'c1', 'uuid' => 'x']));
        self::assertNull(Verifier::objectId(['type' => 'payment']));
        // Which field identifies a kind this SDK does not know is not guessed.
        self::assertNull(Verifier::objectId(Verifier::parse('{"type":"refund","refund_id":"r1","uuid":"x"}')));
    }
}
