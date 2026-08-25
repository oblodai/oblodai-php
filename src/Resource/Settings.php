<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\AcceptedMethod;
use Oblodai\Contract\Model\AccuracyConfig;
use Oblodai\Contract\Model\ApiAllowlist;
use Oblodai\Contract\Model\AutoRefundConfig;
use Oblodai\Contract\Model\AutoWithdrawRule;
use Oblodai\Contract\Model\DiscountRule;
use Oblodai\Contract\Model\OkResult;
use Oblodai\Contract\Model\PaymentFeeConfig;
use Oblodai\Contract\Request\AutoWithdrawSetRequest;
use Oblodai\Contract\Request\PaymentAcceptedListRequest;
use Oblodai\Contract\Request\PaymentAcceptedSetRequest;
use Oblodai\Contract\Request\PaymentAccuracySetRequest;
use Oblodai\Contract\Request\PaymentAutorefundSetRequest;
use Oblodai\Contract\Request\PaymentDiscountListRequest;
use Oblodai\Contract\Request\PaymentDiscountSetRequest;
use Oblodai\Contract\Request\PaymentFeeConfigSetRequest;
use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;

/** Merchant-level configuration exposed over the API. */
final class Settings extends Resource
{
    /**
     * `POST /v1/payment/discount/set` — payer-facing discount/markup per currency+network.
     *
     * @param array<string, mixed>|PaymentDiscountSetRequest $params
     */
    public function setDiscount(
        array|PaymentDiscountSetRequest $params,
        ?RequestOptions $options = null,
    ): DiscountRule {
        return $this->call('POST /v1/payment/discount/set', $params, $options, DiscountRule::fromArray(...));
    }

    /**
     * `POST /v1/payment/discount/list`.
     *
     * @param  array<string, mixed>|PaymentDiscountListRequest $params
     * @return Page<DiscountRule>
     */
    public function listDiscounts(
        array|PaymentDiscountListRequest $params = [],
        ?RequestOptions $options = null,
    ): Page {
        return $this->page('POST /v1/payment/discount/list', $params, DiscountRule::fromArray(...), $options);
    }

    /** `POST /v1/payment/accuracy/get` — under/overpayment tolerance. */
    public function getAccuracy(?RequestOptions $options = null): AccuracyConfig
    {
        return $this->call('POST /v1/payment/accuracy/get', null, $options, AccuracyConfig::fromArray(...));
    }

    /**
     * `POST /v1/payment/accuracy/set`.
     *
     * @param array<string, mixed>|PaymentAccuracySetRequest $params
     */
    public function setAccuracy(
        array|PaymentAccuracySetRequest $params,
        ?RequestOptions $options = null,
    ): AccuracyConfig {
        return $this->call('POST /v1/payment/accuracy/set', $params, $options, AccuracyConfig::fromArray(...));
    }

    /** `POST /v1/payment/autorefund/get`. */
    public function getAutoRefund(?RequestOptions $options = null): AutoRefundConfig
    {
        return $this->call('POST /v1/payment/autorefund/get', null, $options, AutoRefundConfig::fromArray(...));
    }

    /**
     * `POST /v1/payment/autorefund/set` — refund over/underpayments automatically.
     *
     * @param array<string, mixed>|PaymentAutorefundSetRequest $params
     */
    public function setAutoRefund(
        array|PaymentAutorefundSetRequest $params,
        ?RequestOptions $options = null,
    ): AutoRefundConfig {
        return $this->call('POST /v1/payment/autorefund/set', $params, $options, AutoRefundConfig::fromArray(...));
    }

    /**
     * `POST /v1/payment/accepted/list` — which currency/network pairs invoices may be paid in.
     *
     * @param  array<string, mixed>|PaymentAcceptedListRequest $params
     * @return Page<AcceptedMethod>
     */
    public function listAccepted(
        array|PaymentAcceptedListRequest $params = [],
        ?RequestOptions $options = null,
    ): Page {
        return $this->page('POST /v1/payment/accepted/list', $params, AcceptedMethod::fromArray(...), $options);
    }

    /**
     * `POST /v1/payment/accepted/set`.
     *
     * @param array<string, mixed>|PaymentAcceptedSetRequest $params
     */
    public function setAccepted(
        array|PaymentAcceptedSetRequest $params,
        ?RequestOptions $options = null,
    ): OkResult {
        return $this->call('POST /v1/payment/accepted/set', $params, $options, OkResult::fromArray(...));
    }

    /** `POST /v1/payment/fee-config/get` — share of the network fee charged to the payer. */
    public function getPaymentFeeConfig(?RequestOptions $options = null): PaymentFeeConfig
    {
        return $this->call('POST /v1/payment/fee-config/get', null, $options, PaymentFeeConfig::fromArray(...));
    }

    /**
     * `POST /v1/payment/fee-config/set`.
     *
     * @param array<string, mixed>|PaymentFeeConfigSetRequest $params
     */
    public function setPaymentFeeConfig(
        array|PaymentFeeConfigSetRequest $params,
        ?RequestOptions $options = null,
    ): PaymentFeeConfig {
        return $this->call('POST /v1/payment/fee-config/set', $params, $options, PaymentFeeConfig::fromArray(...));
    }

    /**
     * `POST /v1/auto-withdraw/list`. Payout key.
     *
     * @return list<AutoWithdrawRule>
     */
    public function listAutoWithdraw(?RequestOptions $options = null): array
    {
        return $this->plainList('POST /v1/auto-withdraw/list', null, AutoWithdrawRule::fromArray(...), $options);
    }

    /**
     * `POST /v1/auto-withdraw/set` — sweep a currency to an address once the balance passes
     * `min_amount`.
     *
     * @param  array<string, mixed>|AutoWithdrawSetRequest $params
     * @return list<AutoWithdrawRule>
     */
    public function setAutoWithdraw(
        array|AutoWithdrawSetRequest $params,
        ?RequestOptions $options = null,
    ): array {
        return $this->plainList('POST /v1/auto-withdraw/set', $params, AutoWithdrawRule::fromArray(...), $options);
    }

    /**
     * `POST /v1/auto-withdraw/delete`.
     *
     * @return list<AutoWithdrawRule>
     */
    public function deleteAutoWithdraw(string $currency, ?RequestOptions $options = null): array
    {
        return $this->plainList(
            'POST /v1/auto-withdraw/delete',
            ['currency' => $currency],
            AutoWithdrawRule::fromArray(...),
            $options
        );
    }

    /** `POST /v1/api-allowlist/list` — source IPs allowed to use the API keys. Payout key. */
    public function listApiAllowlist(?RequestOptions $options = null): ApiAllowlist
    {
        return $this->call('POST /v1/api-allowlist/list', null, $options, ApiAllowlist::fromArray(...));
    }

    /** `POST /v1/api-allowlist/add`. */
    public function addApiAllowlist(string $cidr, ?RequestOptions $options = null): ApiAllowlist
    {
        return $this->call('POST /v1/api-allowlist/add', ['cidr' => $cidr], $options, ApiAllowlist::fromArray(...));
    }

    /** `POST /v1/api-allowlist/remove`. */
    public function removeApiAllowlist(string $cidr, ?RequestOptions $options = null): ApiAllowlist
    {
        return $this->call(
            'POST /v1/api-allowlist/remove',
            ['cidr' => $cidr],
            $options,
            ApiAllowlist::fromArray(...)
        );
    }

    /** `POST /v1/api-allowlist/enable` — switch enforcement on or off (the list is kept). */
    public function enableApiAllowlist(bool $enabled, ?RequestOptions $options = null): ApiAllowlist
    {
        return $this->call(
            'POST /v1/api-allowlist/enable',
            ['enabled' => $enabled],
            $options,
            ApiAllowlist::fromArray(...)
        );
    }
}
