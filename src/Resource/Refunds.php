<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\BatchSubmitted;
use Oblodai\Contract\Model\Payout;
use Oblodai\Contract\Model\Resolution;
use Oblodai\Contract\Request\PaymentRefundRequest;
use Oblodai\Contract\Request\PaymentResolveRequest;
use Oblodai\Contract\Request\RefundBatchRequest;
use Oblodai\Core\RequestOptions;

/** Refunds are payouts in the invoice's own asset; underpayments are resolved (accept or refund). */
final class Refunds extends Resource
{
    /**
     * `POST /v1/payment/refund` — refund a paid invoice, fully or partially. Requires the payout key.
     *
     * Codes worth branching on: `refund.nothing_to_refund`, `refund.exceeds_refundable`,
     * `refund.no_address` (the payer address is not refundable — ask for one),
     * `refund.dust` (below the network's minimum), `refund.reference_collision`,
     * `payout.insufficient_funds` (retryable).
     *
     * @param array<string, mixed>|PaymentRefundRequest $params
     */
    public function create(array|PaymentRefundRequest $params, ?RequestOptions $options = null): Payout
    {
        return $this->call('POST /v1/payment/refund', $params, $options, Payout::fromArray(...));
    }

    /**
     * `POST /v1/payment/resolve` — settle an underpaid (`wrong_amount`) invoice.
     *
     * Codes worth branching on: `payment.not_found`, `payment.bad_status` (not `wrong_amount`),
     * `refund.nothing_to_refund`, `refund.no_address`, `refund.exceeds_excess`.
     *
     * @param array<string, mixed>|PaymentResolveRequest $params
     */
    public function resolve(array|PaymentResolveRequest $params, ?RequestOptions $options = null): Resolution
    {
        return $this->call('POST /v1/payment/resolve', $params, $options, Resolution::fromArray(...));
    }

    /**
     * `POST /v1/refund/batch` — up to 5000 refunds; track with `batches->info()`.
     *
     * Codes worth branching on: `payout.batch_too_large`, `payout.empty_batch`,
     * `refund.reference_collision`, `request.missing_field` (an item without `reference`),
     * `idempotency.key_reused`.
     *
     * @param array<string, mixed>|RefundBatchRequest $params
     */
    public function batch(array|RefundBatchRequest $params, ?RequestOptions $options = null): BatchSubmitted
    {
        return $this->call('POST /v1/refund/batch', $params, $options, BatchSubmitted::fromArray(...));
    }
}
