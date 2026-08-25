<?php

declare(strict_types=1);

namespace Oblodai\Helper;

use Oblodai\Contract\Enum\PaymentStatus;
use Oblodai\Contract\Enum\PayoutStatus;

/**
 * Reading a status without memorising the vocabulary.
 *
 * Payment: `select → created → confirm_check → paid | paid_over | wrong_amount | expired | cancelled`.
 * Payout:  `pending → approved → awaiting_cosign → broadcasting → sent → confirmed | failed | cancelled`.
 */
final class Status
{
    /** Invoice statuses after which nothing else can happen. */
    public const FINAL_PAYMENT_STATUSES = [
        PaymentStatus::Paid,
        PaymentStatus::PaidOver,
        PaymentStatus::WrongAmount,
        PaymentStatus::Expired,
        PaymentStatus::Cancelled,
    ];

    /** Payout statuses after which nothing else can happen. */
    public const FINAL_PAYOUT_STATUSES = [
        PayoutStatus::Confirmed,
        PayoutStatus::Failed,
        PayoutStatus::Cancelled,
    ];

    public static function isPaymentFinal(PaymentStatus|string $status): bool
    {
        return in_array(self::payment($status), self::FINAL_PAYMENT_STATUSES, true);
    }

    /** `paid` or `paid_over` — the merchant has the money. `wrong_amount` is NOT paid: resolve it. */
    public static function isPaymentPaid(PaymentStatus|string $status): bool
    {
        $value = self::payment($status);

        return $value === PaymentStatus::Paid || $value === PaymentStatus::PaidOver;
    }

    /** The invoice is waiting for a merchant decision (underpaid): call `refunds->resolve()`. */
    public static function isPaymentUnderpaid(PaymentStatus|string $status): bool
    {
        return self::payment($status) === PaymentStatus::WrongAmount;
    }

    public static function isPayoutFinal(PayoutStatus|string $status): bool
    {
        return in_array(self::payout($status), self::FINAL_PAYOUT_STATUSES, true);
    }

    /** The payout reached the chain and is irreversible. */
    public static function isPayoutSucceeded(PayoutStatus|string $status): bool
    {
        return self::payout($status) === PayoutStatus::Confirmed;
    }

    private static function payment(PaymentStatus|string $status): ?PaymentStatus
    {
        return $status instanceof PaymentStatus ? $status : PaymentStatus::tryFrom($status);
    }

    private static function payout(PayoutStatus|string $status): ?PayoutStatus
    {
        return $status instanceof PayoutStatus ? $status : PayoutStatus::tryFrom($status);
    }
}
