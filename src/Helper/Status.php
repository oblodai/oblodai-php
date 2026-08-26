<?php

declare(strict_types=1);

namespace Oblodai\Helper;

use Oblodai\Contract\Enum\PaymentStatus;
use Oblodai\Contract\Enum\PayoutStatus;
use Oblodai\Contract\Model\OpenEnum;

/**
 * Reading a status without memorising the vocabulary.
 *
 * Payment: `select → created → confirm_check → paid | paid_over | wrong_amount | expired | cancelled`.
 * Payout:  `pending → approved → awaiting_cosign → broadcasting → sent → confirmed | failed | cancelled`.
 *
 * Every helper takes what a model carries ({@see OpenEnum}), a typed case, or a raw wire string; a
 * status this snapshot does not know is simply neither final nor paid.
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

    /** @param OpenEnum<PaymentStatus>|PaymentStatus|string $status */
    public static function isPaymentFinal(OpenEnum|PaymentStatus|string $status): bool
    {
        return in_array(self::payment($status), self::FINAL_PAYMENT_STATUSES, true);
    }

    /**
     * `paid` or `paid_over` — the merchant has the money. `wrong_amount` is NOT paid: resolve it.
     *
     * @param OpenEnum<PaymentStatus>|PaymentStatus|string $status
     */
    public static function isPaymentPaid(OpenEnum|PaymentStatus|string $status): bool
    {
        $value = self::payment($status);

        return $value === PaymentStatus::Paid || $value === PaymentStatus::PaidOver;
    }

    /**
     * The invoice is waiting for a merchant decision (underpaid): call `refunds->resolve()`.
     *
     * @param OpenEnum<PaymentStatus>|PaymentStatus|string $status
     */
    public static function isPaymentUnderpaid(OpenEnum|PaymentStatus|string $status): bool
    {
        return self::payment($status) === PaymentStatus::WrongAmount;
    }

    /** @param OpenEnum<PayoutStatus>|PayoutStatus|string $status */
    public static function isPayoutFinal(OpenEnum|PayoutStatus|string $status): bool
    {
        return in_array(self::payout($status), self::FINAL_PAYOUT_STATUSES, true);
    }

    /**
     * The payout reached the chain and is irreversible.
     *
     * @param OpenEnum<PayoutStatus>|PayoutStatus|string $status
     */
    public static function isPayoutSucceeded(OpenEnum|PayoutStatus|string $status): bool
    {
        return self::payout($status) === PayoutStatus::Confirmed;
    }

    /** @param OpenEnum<PaymentStatus>|PaymentStatus|string $status */
    private static function payment(OpenEnum|PaymentStatus|string $status): ?PaymentStatus
    {
        if ($status instanceof PaymentStatus) {
            return $status;
        }

        return PaymentStatus::tryFrom($status instanceof OpenEnum ? $status->value : $status);
    }

    /** @param OpenEnum<PayoutStatus>|PayoutStatus|string $status */
    private static function payout(OpenEnum|PayoutStatus|string $status): ?PayoutStatus
    {
        if ($status instanceof PayoutStatus) {
            return $status;
        }

        return PayoutStatus::tryFrom($status instanceof OpenEnum ? $status->value : $status);
    }
}
