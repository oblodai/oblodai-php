<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Contract\Enum\PaymentStatus;
use Oblodai\Contract\Enum\PayoutStatus;
use Oblodai\Helper\Money;
use Oblodai\Helper\Status;
use PHPUnit\Framework\TestCase;

/** Ports the "helpers" describe block of test/unit/config.test.ts (money + status vocabulary). */
final class MoneyTest extends TestCase
{
    public function testMoneyHelpersWorkAtArbitraryPrecision(): void
    {
        self::assertSame('0.3', Money::add('0.1', '0.2'));
        self::assertSame('10.500000', Money::add('10.000000', '0.5'));
        self::assertSame('-0.000001', Money::subtract('1', '1.000001'));
        self::assertSame(0, Money::compare('25', '25.000000'));
        self::assertSame(1, Money::compare('0.000000000000000001', '0'));
        self::assertTrue(Money::isZero('0.000000'));
    }

    public function testStatusHelpersFollowTheCoreVocabularyAsStrings(): void
    {
        self::assertTrue(Status::isPaymentPaid('paid_over'));
        self::assertFalse(Status::isPaymentPaid('wrong_amount'));
        self::assertFalse(Status::isPaymentFinal('confirm_check'));
        self::assertFalse(Status::isPayoutFinal('sent'));
        self::assertTrue(Status::isPayoutFinal('confirmed'));
    }

    public function testStatusHelpersFollowTheCoreVocabularyAsEnums(): void
    {
        self::assertTrue(Status::isPaymentPaid(PaymentStatus::PaidOver));
        self::assertFalse(Status::isPaymentPaid(PaymentStatus::WrongAmount));
        self::assertFalse(Status::isPaymentFinal(PaymentStatus::ConfirmCheck));
        self::assertFalse(Status::isPayoutFinal(PayoutStatus::Sent));
        self::assertTrue(Status::isPayoutFinal(PayoutStatus::Confirmed));
    }
}
