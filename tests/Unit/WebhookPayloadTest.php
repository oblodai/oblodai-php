<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Contract\Model\PaymentEvent;
use Oblodai\Contract\Model\UnknownEvent;
use Oblodai\Core\Signer;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\ContractException;
use Oblodai\Exception\SignatureException;
use Oblodai\Exception\WebhookPayloadException;
use Oblodai\Webhook\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * What a verified delivery may contain — and what a receiver must NOT turn into a 401.
 *
 * The gateway retries a non-2xx answer for about 26 hours. So the only failures allowed to look
 * like "not ours" are the ones where the MAC did not match; a body the SDK cannot read, an event
 * type it has never seen, or a status added after this release must all stay recoverable.
 */
final class WebhookPayloadTest extends TestCase
{
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

    public function testAnUnknownEventTypeIsHandedOverInsteadOfThrowing(): void
    {
        $event = Verifier::parse('{"type":"alien","uuid":"x","sequence":9,"is_final":true,"test":true}');

        self::assertInstanceOf(UnknownEvent::class, $event);
        self::assertSame('alien', $event->type());
        self::assertSame('x', $event->uuid());
        self::assertSame(9, $event->sequence());
        self::assertTrue($event->isTest());
        self::assertTrue(Verifier::isTestEvent($event));
        self::assertTrue(Verifier::isStale($event, 9));
        self::assertFalse(Verifier::isStale($event, 8));
        self::assertSame('alien', $event->toArray()['type'] ?? null);
        self::assertFalse(Verifier::isKnownEvent($event));
    }

    public function testIsKnownEventRecognisesTheModelledKinds(): void
    {
        foreach (['payment', 'payout', 'wallet'] as $type) {
            $event = Verifier::parse((string) json_encode(['type' => $type, 'uuid' => 'u', 'status' => 'paid']));
            self::assertTrue(Verifier::isKnownEvent($event), $type . ' is modelled');
        }
    }

    public function testAStatusOutsideTheSnapshotDoesNotThrowAndKeepsTheRawString(): void
    {
        $raw = (string) json_encode([
            'type' => 'payment',
            'uuid' => 'u1',
            'status' => 'quantum_settled',
            'is_final' => true,
            'sequence' => 3,
        ]);

        $event = Verifier::parse($raw);

        self::assertInstanceOf(PaymentEvent::class, $event);
        self::assertSame('quantum_settled', $event->status->value);
        self::assertFalse($event->status->isKnown());
        self::assertNull($event->status->known);
    }

    public function testAVerifiedButUnreadableBodyIsAContractErrorNotASignatureError(): void
    {
        $raw = 'not json at all';
        $headers = [
            'X-Webhook-Timestamp' => (string) self::TS,
            'x-webhook-signature' => Signer::signWebhook('whsec', self::TS, $raw),
        ];

        try {
            Verifier::verify($raw, $headers, 'whsec', now: self::TS);
            self::fail('expected a ContractException');
        } catch (WebhookPayloadException $e) {
            // A receiver that answers 401 on SignatureException must not answer 401 here: the MAC
            // verified, so the event is authentic and a 401 would have it redelivered for a day.
            self::assertInstanceOf(ContractException::class, $e, 'contract family, not signature');
            self::assertSame('webhook.bad_payload', $e->errorCode);
            self::assertSame('webhook', $e->family());
            self::assertNotSame(SignatureException::BAD_SIGNATURE, $e->errorCode);
        }
    }

    public function testAJsonArrayBodyIsAlsoABadPayload(): void
    {
        $this->expectException(WebhookPayloadException::class);
        $this->expectExceptionMessageMatches('/not a JSON object/');

        Verifier::parse('[1,2,3]');
    }

    public function testABodyWithoutATypeIsABadPayload(): void
    {
        try {
            Verifier::parse('{"uuid":"x"}');
            self::fail('expected a WebhookPayloadException');
        } catch (WebhookPayloadException $e) {
            self::assertSame('webhook.bad_payload', $e->errorCode);
        }
    }

    public function testAnEmptySecretIsRefusedBeforeAnyCrypto(): void
    {
        // HMAC('', body) is computable by anybody, so verifying with an empty key accepts forgeries.
        $forged = (string) json_encode(['type' => 'payment', 'uuid' => 'forged', 'status' => 'paid']);
        $headers = [
            'X-Webhook-Timestamp' => (string) self::TS,
            'x-webhook-signature' => Signer::signWebhook('', self::TS, $forged),
        ];

        foreach (['', '   '] as $empty) {
            try {
                Verifier::verify($forged, $headers, $empty, now: self::TS);
                self::fail('an empty secret must never verify anything');
            } catch (ConfigException $e) {
                self::assertSame('sdk.bad_config', $e->errorCode);
                self::assertSame('secret', $e->field);
            }
        }
    }

    public function testAnEmptyPreviousSecretIsRefused(): void
    {
        try {
            Verifier::verify(self::body(), self::headers(), 'whsec', previousSecret: '', now: self::TS);
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('previousSecret', $e->field);
        }
    }

    public function testANegativeToleranceIsAConfigurationError(): void
    {
        try {
            Verifier::verify(self::body(), self::headers(), 'whsec', toleranceSec: -1, now: self::TS);
            self::fail('expected a ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('toleranceSec', $e->field);
        }
    }

    /**
     * The MAC is checked before the clock. Otherwise an unauthenticated caller could probe the
     * freshness window — send a timestamp with any signature and learn the receiver's time from
     * which answer comes back.
     */
    public function testTheSignatureIsCheckedBeforeTheFreshnessWindow(): void
    {
        $headers = self::headers(['X-Webhook-Timestamp' => (string) (self::TS - 100_000)]);

        try {
            Verifier::verify(self::body(), $headers, 'whsec', now: self::TS);
            self::fail('expected a SignatureException');
        } catch (SignatureException $e) {
            self::assertSame(SignatureException::BAD_SIGNATURE, $e->errorCode, 'MAC must be judged first');
            self::assertStringNotContainsString('outside', $e->getMessage());
        }
    }

    public function testASignatureHeaderIsTrimmedAndCaseInsensitiveButRejects0x(): void
    {
        $signature = Signer::signWebhook('whsec', self::TS, self::body());

        foreach ([" {$signature} ", strtoupper($signature), "\t" . $signature . "\n"] as $variant) {
            $delivery = Verifier::verify(
                self::body(),
                self::headers(['x-webhook-signature' => $variant]),
                'whsec',
                now: self::TS
            );
            self::assertSame('u1', $delivery->event->uuid());
        }

        try {
            Verifier::verify(
                self::body(),
                self::headers(['x-webhook-signature' => '0x' . $signature]),
                'whsec',
                now: self::TS
            );
            self::fail('a 0x-prefixed signature must not be accepted');
        } catch (SignatureException $e) {
            self::assertSame(SignatureException::MISSING_HEADER, $e->errorCode);
        }
    }

    public function testAnEventWithoutASequenceIsNeverStale(): void
    {
        $event = Verifier::parse((string) json_encode([
            'type' => 'payment', 'uuid' => 'u1', 'status' => 'paid', 'is_final' => true,
        ]));

        self::assertNull($event->sequence());
        self::assertFalse(Verifier::isStale($event, 0));
        self::assertFalse(Verifier::isStale($event, 1_000_000));
        self::assertFalse(Verifier::isStale($event, null));
    }

    public function testANonIntegerSequenceIsNeverStale(): void
    {
        $event = Verifier::parse((string) json_encode([
            'type' => 'payment', 'uuid' => 'u1', 'status' => 'paid', 'sequence' => 'later',
        ]));

        self::assertNull($event->sequence());
        self::assertFalse(Verifier::isStale($event, 5));
    }
}
