<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\BatchElement;
use Oblodai\Contract\Model\BatchSubmitted;
use Oblodai\Contract\Model\Payout;
use Oblodai\Contract\Model\PayoutCalculation;
use Oblodai\Contract\Model\PayoutFeeConfig;
use Oblodai\Contract\Model\PayoutValidation;
use Oblodai\Contract\Model\RefundFeeConfig;
use Oblodai\Contract\Model\ServiceMethod;
use Oblodai\Contract\Request\PayoutBatchRequest;
use Oblodai\Contract\Request\PayoutCalculateRequest;
use Oblodai\Contract\Request\PayoutFeeConfigSetRequest;
use Oblodai\Contract\Request\PayoutHistoryRequest;
use Oblodai\Contract\Request\PayoutMassRequest;
use Oblodai\Contract\Request\PayoutRefundFeeConfigSetRequest;
use Oblodai\Contract\Request\PayoutRequest;
use Oblodai\Contract\Request\PayoutServicesRequest;
use Oblodai\Contract\Request\PayoutValidateRequest;
use Oblodai\Core\Envelope;
use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;

/**
 * Outgoing transfers to external addresses. Every route here needs the payout key.
 *
 * A lookup argument is either the payout `uuid` as a string or an array with `uuid` or `order_id`.
 */
final class Payouts extends Resource
{
    /**
     * `POST /v1/payout` — create and (for API keys) auto-approve a payout. Idempotent by `order_id`
     * and Idempotency-Key. Errors to handle: `payout.insufficient_funds` (retryable),
     * `payout.funds_maturing`, `payout.bad_address`, `payout.memo_required`.
     *
     * @param array<string, mixed>|PayoutRequest $params
     */
    public function create(array|PayoutRequest $params, ?RequestOptions $options = null): Payout
    {
        return $this->call('POST /v1/payout', $params, $options, Payout::fromArray(...));
    }

    /**
     * `POST /v1/payout/validate` — dry run: every check of `create`, nothing reserved or sent.
     *
     * @param array<string, mixed>|PayoutValidateRequest $params
     */
    public function validate(array|PayoutValidateRequest $params, ?RequestOptions $options = null): PayoutValidation
    {
        return $this->call('POST /v1/payout/validate', $params, $options, PayoutValidation::fromArray(...));
    }

    /**
     * `POST /v1/payout/calculate` — commission and net amount without creating anything.
     *
     * @param array<string, mixed>|PayoutCalculateRequest $params
     */
    public function calculate(
        array|PayoutCalculateRequest $params,
        ?RequestOptions $options = null,
    ): PayoutCalculation {
        return $this->call('POST /v1/payout/calculate', $params, $options, PayoutCalculation::fromArray(...));
    }

    /**
     * `POST /v1/payout/info` — by `uuid` or `order_id`. Refunds are payouts too (`is_refund`).
     *
     * @param string|array<string, mixed> $lookup
     */
    public function info(string|array $lookup, ?RequestOptions $options = null): Payout
    {
        return $this->call('POST /v1/payout/info', self::refBy($lookup), $options, Payout::fromArray(...));
    }

    /**
     * Alias of `info()`.
     *
     * @param string|array<string, mixed> $lookup
     */
    public function get(string|array $lookup, ?RequestOptions $options = null): Payout
    {
        return $this->info($lookup, $options);
    }

    /**
     * `POST /v1/payout/cancel` — cancel while not yet broadcast (pending/approved/awaiting_cosign);
     * 409 `payout.not_pending` after.
     *
     * @param string|array<string, mixed> $payout
     */
    public function cancel(string|array $payout, ?RequestOptions $options = null): Payout
    {
        return $this->call(
            'POST /v1/payout/cancel',
            ['uuid' => self::idOf($payout)],
            $options,
            Payout::fromArray(...)
        );
    }

    /**
     * `POST /v1/payout/approve` — approve a payout awaiting manual approval.
     *
     * @param string|array<string, mixed> $payout
     */
    public function approve(string|array $payout, ?RequestOptions $options = null): Payout
    {
        return $this->call(
            'POST /v1/payout/approve',
            ['uuid' => self::idOf($payout)],
            $options,
            Payout::fromArray(...)
        );
    }

    /**
     * `POST /v1/payout/history` — newest first. `kind: "refund"` lists refunds only.
     *
     * @param  array<string, mixed>|PayoutHistoryRequest $params
     * @return Page<Payout>
     */
    public function history(array|PayoutHistoryRequest $params = [], ?RequestOptions $options = null): Page
    {
        return $this->page('POST /v1/payout/history', $params, Payout::fromArray(...), $options);
    }

    /**
     * Alias of `history()`.
     *
     * @param  array<string, mixed>|PayoutHistoryRequest $params
     * @return Page<Payout>
     */
    public function list(array|PayoutHistoryRequest $params = [], ?RequestOptions $options = null): Page
    {
        return $this->history($params, $options);
    }

    /**
     * `POST /v1/payout/mass` — SYNCHRONOUS batch (≤100): each element reports its own outcome.
     *
     * @param  array<string, mixed>|PayoutMassRequest $params
     * @return list<BatchElement>
     */
    public function mass(array|PayoutMassRequest $params, ?RequestOptions $options = null): array
    {
        $result = $this->call('POST /v1/payout/mass', $params, $options);
        $items = [];
        foreach (Envelope::asPlainList($result) as $item) {
            $items[] = BatchElement::fromArray(
                self::asObject($item, 'POST /v1/payout/mass'),
                static fn (array $row): Payout => Payout::fromArray($row)
            );
        }

        return $items;
    }

    /**
     * `POST /v1/payout/batch` — ASYNCHRONOUS batch (≤5000): returns a ticket; poll `batches->info()`.
     * `order_id` is required on every item.
     *
     * @param array<string, mixed>|PayoutBatchRequest $params
     */
    public function batch(array|PayoutBatchRequest $params, ?RequestOptions $options = null): BatchSubmitted
    {
        return $this->call('POST /v1/payout/batch', $params, $options, BatchSubmitted::fromArray(...));
    }

    /**
     * `POST /v1/payout/services` — currencies/networks available for payouts.
     *
     * @param  array<string, mixed>|PayoutServicesRequest $params
     * @return Page<ServiceMethod>
     */
    public function services(array|PayoutServicesRequest $params = [], ?RequestOptions $options = null): Page
    {
        return $this->page('POST /v1/payout/services', $params, ServiceMethod::fromArray(...), $options);
    }

    /** `POST /v1/payout/fee-config/get`. */
    public function getFeeConfig(?RequestOptions $options = null): PayoutFeeConfig
    {
        return $this->call('POST /v1/payout/fee-config/get', null, $options, PayoutFeeConfig::fromArray(...));
    }

    /**
     * `POST /v1/payout/fee-config/set` — who bears the network fee by default.
     *
     * @param array<string, mixed>|PayoutFeeConfigSetRequest $params
     */
    public function setFeeConfig(
        array|PayoutFeeConfigSetRequest $params,
        ?RequestOptions $options = null,
    ): PayoutFeeConfig {
        return $this->call('POST /v1/payout/fee-config/set', $params, $options, PayoutFeeConfig::fromArray(...));
    }

    /** `POST /v1/payout/refund-fee-config/get`. */
    public function getRefundFeeConfig(?RequestOptions $options = null): RefundFeeConfig
    {
        return $this->call(
            'POST /v1/payout/refund-fee-config/get',
            null,
            $options,
            RefundFeeConfig::fromArray(...)
        );
    }

    /**
     * `POST /v1/payout/refund-fee-config/set` — who bears the fee on refunds.
     *
     * @param array<string, mixed>|PayoutRefundFeeConfigSetRequest $params
     */
    public function setRefundFeeConfig(
        array|PayoutRefundFeeConfigSetRequest $params,
        ?RequestOptions $options = null,
    ): RefundFeeConfig {
        return $this->call(
            'POST /v1/payout/refund-fee-config/set',
            $params,
            $options,
            RefundFeeConfig::fromArray(...)
        );
    }
}
