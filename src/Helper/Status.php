<?php

declare(strict_types=1);

namespace Oblodai\Helper;

use Oblodai\Generated\Enum\PaymentStatus;
use Oblodai\Generated\Enum\PayoutStatus;

/**
 * Reading a status without memorising the vocabulary.
 *
 * Payment: `select → created → confirm_check → paid | paid_over | wrong_amount | expired | cancelled`.
 * Payout:  `pending → approved → awaiting_cosign → broadcasting → sent → confirmed | failed | cancelled`.
 *
 * Every helper takes what a model carries — a typed case, or the raw wire string of a status this
 * SDK does not know yet (which is simply neither final nor paid).
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

    /** @param PaymentStatus|string $status */
    public static function isPaymentFinal(PaymentStatus|string $status): bool
    {
        return in_array(self::payment($status), self::FINAL_PAYMENT_STATUSES, true);
    }

    /**
     * `paid` or `paid_over` — the merchant has the money. `wrong_amount` is NOT paid: resolve it.
     *
     * @param PaymentStatus|string $status
     */
    public static function isPaymentPaid(PaymentStatus|string $status): bool
    {
        $value = self::payment($status);

        return $value === PaymentStatus::Paid || $value === PaymentStatus::PaidOver;
    }

    /**
     * The invoice is waiting for a merchant decision (underpaid): call `payments->resolve()`.
     *
     * @param PaymentStatus|string $status
     */
    public static function isPaymentUnderpaid(PaymentStatus|string $status): bool
    {
        return self::payment($status) === PaymentStatus::WrongAmount;
    }

    /** @param PayoutStatus|string $status */
    public static function isPayoutFinal(PayoutStatus|string $status): bool
    {
        return in_array(self::payout($status), self::FINAL_PAYOUT_STATUSES, true);
    }

    /**
     * The payout reached the chain and is irreversible.
     *
     * @param PayoutStatus|string $status
     */
    public static function isPayoutSucceeded(PayoutStatus|string $status): bool
    {
        return self::payout($status) === PayoutStatus::Confirmed;
    }

    /**
     * The wire string of any status a model carries — a known case or a newer plain string:
     * `Status::value($invoice->status)` is `"paid"` either way.
     */
    public static function value(\BackedEnum|string $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : $status;
    }

    /** @param PaymentStatus|string $status */
    private static function payment(PaymentStatus|string $status): ?PaymentStatus
    {
        if ($status instanceof PaymentStatus) {
            return $status;
        }

        return PaymentStatus::tryFrom($status);
    }

    /** @param PayoutStatus|string $status */
    private static function payout(PayoutStatus|string $status): ?PayoutStatus
    {
        if ($status instanceof PayoutStatus) {
            return $status;
        }

        return PayoutStatus::tryFrom($status);
    }
}
