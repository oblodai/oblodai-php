<?php

declare(strict_types=1);

namespace Oblodai\Tests\Unit;

use Oblodai\Contract\Enum\PaymentStatus;
use Oblodai\Contract\Enum\PayoutStatus;
use Oblodai\Exception\ConfigException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Helper\Money;
use Oblodai\Helper\Status;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{string}> */
    public static function nonAmounts(): iterable
    {
        yield 'empty' => [''];
        yield 'lone sign' => ['-'];
        yield 'lone dot' => ['.'];
        yield 'no integer part' => ['.5'];
        yield 'no fractional part' => ['5.'];
        yield 'two dots' => ['1.2.3'];
        yield 'comma' => ['1,5'];
        yield 'exponent' => ['1e6'];
        yield 'hex' => ['0x10'];
        yield 'spaces' => [' 1 '];
        yield 'plus' => ['+1'];
        yield 'trailing sign' => ['1-'];
        yield 'infinity' => ['INF'];
        yield 'nan' => ['NAN'];
        yield 'arabic-indic digits' => ["\u{0661}\u{0662}"];
        yield 'null byte' => ["1\0"];
        yield 'too long' => [str_repeat('9', 65)];
    }

    /**
     * Anything that is not a decimal amount is the SDK's own amount error — never a native
     * `InvalidArgumentException`/`TypeError` a caller catching `OblodaiException` would miss.
     */
    #[DataProvider('nonAmounts')]
    public function testANonAmountRaisesTheSdksOwnError(string $value): void
    {
        try {
            Money::compare($value, '1');
            self::fail(sprintf('expected an amount error for %s', json_encode($value)));
        } catch (ConfigException $e) {
            self::assertSame('sdk.bad_amount', $e->errorCode);
            self::assertSame('amount', $e->field);
            self::assertInstanceOf(OblodaiException::class, $e);
        }
    }

    public function testTheLengthCapKeepsAHostileStringFromBecomingWork(): void
    {
        $longest = str_repeat('9', Money::MAX_LENGTH);
        self::assertSame(0, Money::compare($longest, $longest));

        $this->expectException(ConfigException::class);
        Money::assertAmount($longest . '9');
    }

    public function testAssertAmountAcceptsWhatTheGatewayAccepts(): void
    {
        $accepted = ['0', '-0', '25', '25.000000', '-1.5', '0.000000000000000001', str_repeat('1', 40)];
        $rejected = [];
        foreach ($accepted as $amount) {
            try {
                Money::assertAmount($amount);
            } catch (ConfigException $e) {
                $rejected[] = $amount;
            }
        }

        self::assertSame([], $rejected);
    }

    /**
     * Amounts are compared by value, never by string ordering: `"9"` sorts after `"10"` as text and
     * before it as money.
     */
    public function testComparisonIsNumericNotLexicographic(): void
    {
        self::assertSame(-1, Money::compare('9', '10'));
        self::assertGreaterThan(0, strcmp('9', '10'));
        self::assertSame(-1, Money::compare('-10', '-9'));
        self::assertSame(0, Money::compare('-0', '0'));
        self::assertSame(1, Money::compare('0.10', '0.099999'));
    }
}
