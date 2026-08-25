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
     * @param array<string, mixed>|PaymentRefundRequest $params
     */
    public function create(array|PaymentRefundRequest $params, ?RequestOptions $options = null): Payout
    {
        return $this->call('POST /v1/payment/refund', $params, $options, Payout::fromArray(...));
    }

    /**
     * `POST /v1/payment/resolve` — settle an underpaid (`wrong_amount`) invoice.
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
     * @param array<string, mixed>|RefundBatchRequest $params
     */
    public function batch(array|RefundBatchRequest $params, ?RequestOptions $options = null): BatchSubmitted
    {
        return $this->call('POST /v1/refund/batch', $params, $options, BatchSubmitted::fromArray(...));
    }
}
