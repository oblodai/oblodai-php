<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\BatchSubmitted;
use Oblodai\Contract\Model\EmailSent;
use Oblodai\Contract\Model\OkResult;
use Oblodai\Contract\Model\Payment;
use Oblodai\Contract\Model\PublicPayment;
use Oblodai\Contract\Model\QrCode;
use Oblodai\Contract\Model\ServiceMethod;
use Oblodai\Contract\Request\PaymentBatchRequest;
use Oblodai\Contract\Request\PaymentHistoryRequest;
use Oblodai\Contract\Request\PaymentRequest;
use Oblodai\Contract\Request\PaymentSendEmailRequest;
use Oblodai\Contract\Request\PaymentServicesRequest;
use Oblodai\Contract\Request\PaySelectRequest;
use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;

/**
 * Invoices: create, look up, cancel, list, and the payer-facing checkout endpoints. Payment key.
 *
 * A lookup argument is either the invoice `uuid` as a string or an array with `uuid` or `order_id`.
 */
final class Payments extends Resource
{
    /**
     * `POST /v1/payment` — create an invoice. Idempotent by `order_id` and by Idempotency-Key.
     *
     * Codes worth branching on: `payment.bad_amount`, `payment.below_minimum`,
     * `payment.minimum_unavailable` (rate feed down — retryable), `payment.unsupported_network`,
     * `payment.network_required` (multi-network asset, no `network` given),
     * `request.unknown_currency`, `idempotency.key_reused` (same key, different body).
     *
     * @param array<string, mixed>|PaymentRequest $params
     */
    public function create(array|PaymentRequest $params, ?RequestOptions $options = null): Payment
    {
        return $this->call('POST /v1/payment', $params, $options, Payment::fromArray(...));
    }

    /**
     * `POST /v1/payment/info` — by `uuid` or `order_id`; includes `refunds` and `refund_status`.
     *
     * @param string|array<string, mixed> $lookup
     */
    public function info(string|array $lookup, ?RequestOptions $options = null): Payment
    {
        return $this->call('POST /v1/payment/info', self::refBy($lookup), $options, Payment::fromArray(...));
    }

    /**
     * Alias of `info()`.
     *
     * @param string|array<string, mixed> $lookup
     */
    public function get(string|array $lookup, ?RequestOptions $options = null): Payment
    {
        return $this->info($lookup, $options);
    }

    /**
     * `POST /v1/payment/cancel` — cancel an unpaid invoice (409 `invoice.not_payable` once a
     * deposit was seen).
     *
     * @param string|array<string, mixed> $lookup
     */
    public function cancel(string|array $lookup, ?RequestOptions $options = null): Payment
    {
        return $this->call('POST /v1/payment/cancel', self::refBy($lookup), $options, Payment::fromArray(...));
    }

    /**
     * `POST /v1/payment/history` — newest first; the page walks every invoice when iterated.
     *
     * @param  array<string, mixed>|PaymentHistoryRequest $params
     * @return Page<Payment>
     */
    public function history(array|PaymentHistoryRequest $params = [], ?RequestOptions $options = null): Page
    {
        return $this->page('POST /v1/payment/history', $params, Payment::fromArray(...), $options);
    }

    /**
     * Alias of `history()`.
     *
     * @param  array<string, mixed>|PaymentHistoryRequest $params
     * @return Page<Payment>
     */
    public function list(array|PaymentHistoryRequest $params = [], ?RequestOptions $options = null): Page
    {
        return $this->history($params, $options);
    }

    /**
     * `POST /v1/payment/batch` — create up to 5000 invoices asynchronously; track with
     * `batches->info()`.
     *
     * Codes worth branching on: `payment.bad_amount`, `payment.below_minimum`,
     * `request.unknown_currency`, `request.missing_field` (an item without `order_id`),
     * `payout.batch_too_large`, `idempotency.key_reused`.
     *
     * @param array<string, mixed>|PaymentBatchRequest $params
     */
    public function batch(array|PaymentBatchRequest $params, ?RequestOptions $options = null): BatchSubmitted
    {
        return $this->call('POST /v1/payment/batch', $params, $options, BatchSubmitted::fromArray(...));
    }

    /**
     * `POST /v1/payment/qr` — QR image of the invoice's payment URI.
     *
     * @param string|array<string, mixed> $lookup
     */
    public function qr(string|array $lookup, ?RequestOptions $options = null): QrCode
    {
        return $this->call('POST /v1/payment/qr', self::refBy($lookup), $options, QrCode::fromArray(...));
    }

    /**
     * `POST /v1/payment/services` — currencies/networks accepted for deposits, with limits and fees.
     *
     * @param  array<string, mixed>|PaymentServicesRequest $params
     * @return Page<ServiceMethod>
     */
    public function services(array|PaymentServicesRequest $params = [], ?RequestOptions $options = null): Page
    {
        return $this->page('POST /v1/payment/services', $params, ServiceMethod::fromArray(...), $options);
    }

    /**
     * `POST /v1/payment/send-email` — email the receipt (defaults to the invoice's `payer_email`).
     *
     * @param array<string, mixed>|PaymentSendEmailRequest $params
     */
    public function sendEmail(array|PaymentSendEmailRequest $params, ?RequestOptions $options = null): EmailSent
    {
        return $this->call('POST /v1/payment/send-email', $params, $options, EmailSent::fromArray(...));
    }

    /**
     * `POST /v1/payment/resend` — re-deliver the invoice's last webhook.
     *
     * @param string|array<string, mixed> $lookup
     */
    public function resend(string|array $lookup, ?RequestOptions $options = null): OkResult
    {
        return $this->call('POST /v1/payment/resend', self::refBy($lookup), $options, OkResult::fromArray(...));
    }

    // --- payer-facing (public, unsigned) — for custom checkout pages ---

    /** `GET /v1/pay/{id}` — the invoice as the payer sees it. No credentials needed. */
    public function publicView(string $uuid, ?RequestOptions $options = null): PublicPayment
    {
        return $this->call(
            'GET /v1/pay/{id}',
            null,
            $options,
            PublicPayment::fromArray(...),
            ['id' => $uuid]
        );
    }

    /**
     * `POST /v1/pay/{id}/select` — pick the asset/network on a multi-currency invoice.
     * No credentials needed.
     *
     * @param array<string, mixed>|PaySelectRequest $params
     */
    public function select(
        string $uuid,
        array|PaySelectRequest $params,
        ?RequestOptions $options = null,
    ): PublicPayment {
        return $this->call(
            'POST /v1/pay/{id}/select',
            $params,
            $options,
            PublicPayment::fromArray(...),
            ['id' => $uuid]
        );
    }

    /** `GET /v1/pay/{id}/qr` — QR for the payer page. No credentials needed. */
    public function publicQr(string $uuid, ?RequestOptions $options = null): QrCode
    {
        return $this->call('GET /v1/pay/{id}/qr', null, $options, QrCode::fromArray(...), ['id' => $uuid]);
    }
}
