<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Contract\Enum\PaymentStatus;
use Oblodai\Contract\Enum\PayoutStatus;
use Oblodai\Contract\Model\OpenEnum;
use Oblodai\Contract\Model\Payment;
use Oblodai\Contract\Model\Payout;
use Oblodai\Contract\Model\WebhookDelivery;
use Oblodai\Contract\Model\Wire;
use Oblodai\Exception\ContractException;
use Oblodai\Helper\Status;
use PHPUnit\Framework\TestCase;

/**
 * Closed vocabularies are decoded openly.
 *
 * The gateway adds statuses on its own schedule. Refusing the first unfamiliar one used to throw
 * `ContractException` inside a model constructor — which, in a webhook receiver, means a 500 for an
 * authentic delivery and the gateway retrying it for 26 hours.
 */
final class OpenEnumTest extends TestCase
{
    public function testAKnownValueDecodesToItsCase(): void
    {
        $payment = Payment::fromArray(['uuid' => 'u', 'status' => 'paid']);

        self::assertSame('paid', $payment->status->value);
        self::assertSame(PaymentStatus::Paid, $payment->status->known);
        self::assertTrue($payment->status->isKnown());
        self::assertTrue($payment->status->is(PaymentStatus::Paid));
        self::assertTrue($payment->status->is('paid'));
        self::assertTrue($payment->status->isOneOf(PaymentStatus::PaidOver, 'paid'));
        self::assertFalse($payment->status->is(PaymentStatus::Expired));
    }

    public function testAnUnknownValueDecodesWithoutThrowingAndKeepsTheRawString(): void
    {
        $payment = Payment::fromArray(['uuid' => 'u', 'status' => 'quantum_settled']);

        self::assertSame('quantum_settled', $payment->status->value);
        self::assertNull($payment->status->known);
        self::assertFalse($payment->status->isKnown());
        self::assertTrue($payment->status->is('quantum_settled'));
        self::assertFalse($payment->status->is(PaymentStatus::Paid));
        self::assertSame('quantum_settled', (string) $payment->status);
        self::assertSame('"quantum_settled"', json_encode($payment->status));
    }

    public function testUnknownValuesAreToleratedAcrossEveryVocabulary(): void
    {
        $payout = Payout::fromArray(['uuid' => 'p', 'status' => 'teleporting', 'fee_bearer' => 'the_moon']);
        self::assertSame('teleporting', $payout->status->value);
        self::assertSame('the_moon', $payout->fee_bearer->value);

        $delivery = WebhookDelivery::fromArray(['uuid' => 'd', 'status' => 'quarantined']);
        self::assertSame('quarantined', $delivery->status->value);
    }

    /** An absent field falls back to the documented default rather than to an empty string. */
    public function testAnAbsentFieldUsesTheDefaultWhenOneIsGiven(): void
    {
        $decoded = Wire::enum(PayoutStatus::class, [], 'status', PayoutStatus::Pending);

        self::assertSame('pending', $decoded->value);
        self::assertSame(PayoutStatus::Pending, $decoded->known);
    }

    public function testStrictModeTurnsDriftBackIntoALoudFailure(): void
    {
        Wire::strict();

        try {
            self::assertTrue(Wire::isStrict());
            $this->expectException(ContractException::class);
            Payment::fromArray(['uuid' => 'u', 'status' => 'quantum_settled']);
        } finally {
            Wire::strict(false);
        }
    }

    public function testStrictModeStillAcceptsKnownValues(): void
    {
        Wire::strict();

        try {
            $payment = Payment::fromArray(['uuid' => 'u', 'status' => 'paid']);
            self::assertSame(PaymentStatus::Paid, $payment->status->known);
        } finally {
            Wire::strict(false);
        }
        self::assertFalse(Wire::isStrict());
    }

    public function testStatusHelpersAcceptTheOpenValueAndSayNoToUnknownOnes(): void
    {
        self::assertTrue(Status::isPaymentPaid(OpenEnum::from(PaymentStatus::Paid)));
        self::assertTrue(Status::isPaymentFinal(OpenEnum::from(PaymentStatus::Cancelled)));
        self::assertFalse(Status::isPaymentPaid(OpenEnum::of(PaymentStatus::class, 'quantum_settled')));
        self::assertFalse(Status::isPaymentFinal(OpenEnum::of(PaymentStatus::class, 'quantum_settled')));
        self::assertFalse(Status::isPayoutFinal(OpenEnum::of(PayoutStatus::class, 'teleporting')));
        self::assertTrue(Status::isPayoutSucceeded(OpenEnum::from(PayoutStatus::Confirmed)));
    }

    /** The model keeps the untouched wire body, so an unknown value is never lost. */
    public function testTheRawBodySurvivesAnUnknownValue(): void
    {
        $payment = Payment::fromArray(['uuid' => 'u', 'status' => 'quantum_settled', 'extra' => 1]);

        self::assertSame('quantum_settled', $payment->raw['status'] ?? null);
        self::assertSame(1, $payment->toArray()['extra'] ?? null);
    }
}
