<?php

declare(strict_types=1);

namespace Oblodai\Helper;

use Oblodai\Generated\Enum\PaymentStatus;
use Oblodai\Generated\Enum\PayoutStatus;

/**
 * Reading a status without memorising the vocabulary. Which statuses are final, and which of those
 * mean success, is the contract's (`x-status-classes`): the generated enums carry it as
 * `PaymentStatus::FINAL`, `::SUCCESS`, `->isFinal()` and `->isSuccess()`; these helpers are thin
 * wrappers over them.
 *
 * Every helper takes what a model carries — a typed case, or the raw wire string of a status this
 * SDK does not know yet (which is simply neither final nor paid).
 */
final class Status
{
    /** Invoice statuses after which nothing else can happen. */
    public const FINAL_PAYMENT_STATUSES = PaymentStatus::FINAL;

    /** Payout statuses after which nothing else can happen. */
    public const FINAL_PAYOUT_STATUSES = PayoutStatus::FINAL;

    /** @param PaymentStatus|string $status */
    public static function isPaymentFinal(PaymentStatus|string $status): bool
    {
        return self::payment($status)?->isFinal() ?? false;
    }

    /**
     * The merchant has the money (`paid`, `paid_over` — {@see PaymentStatus::SUCCESS}).
     * `wrong_amount` is NOT paid: resolve it.
     *
     * @param PaymentStatus|string $status
     */
    public static function isPaymentPaid(PaymentStatus|string $status): bool
    {
        return self::payment($status)?->isSuccess() ?? false;
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
        return self::payout($status)?->isFinal() ?? false;
    }

    /**
     * The payout reached the chain and is irreversible ({@see PayoutStatus::SUCCESS}).
     *
     * @param PayoutStatus|string $status
     */
    public static function isPayoutSucceeded(PayoutStatus|string $status): bool
    {
        return self::payout($status)?->isSuccess() ?? false;
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
