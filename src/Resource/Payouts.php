<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Выплаты и возвраты.
 */
final class Payouts extends AbstractResource
{
    /**
     * Создать выплату. POST /v1/payout
     *
     * @param array<string,mixed> $params amount, currency, order_id, address, network, ...
     *
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('/v1/payout', $params);
    }

    /**
     * Массовая выплата. POST /v1/payout/mass
     *
     * @param array<int,array<string,mixed>> $payouts
     *
     * @return array<string,mixed>
     */
    public function createMass(array $payouts, ?string $source = null): array
    {
        $p = ['payouts' => $payouts];
        if ($source !== null) {
            $p['source'] = $source;
        }

        return $this->client->request('/v1/payout/mass', $p);
    }

    /** @return array<string,mixed> */
    public function info(?string $uuid = null, ?string $orderId = null): array
    {
        $p = [];
        if ($uuid !== null) {
            $p['uuid'] = $uuid;
        }
        if ($orderId !== null) {
            $p['order_id'] = $orderId;
        }

        return $this->client->request('/v1/payout/info', $p);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public function history(array $params = []): array
    {
        return $this->client->request('/v1/payout/history', $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function services(): array
    {
        return $this->client->request('/v1/payout/services', []);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public function calculate(array $params): array
    {
        return $this->client->request('/v1/payout/calculate', $params);
    }

    /** @return array<string,mixed> */
    public function approve(string $uuid): array
    {
        return $this->client->request('/v1/payout/approve', ['uuid' => $uuid]);
    }

    /**
     * Возврат платежа. POST /v1/payment/refund (тот же метод, что и Payments::refund).
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public function refund(array $params): array
    {
        return $this->client->request('/v1/payment/refund', $params);
    }

    /** @return array<string,mixed> */
    public function getFeeConfig(): array
    {
        return $this->client->request('/v1/payout/fee-config/get', []);
    }

    /** @return array<string,mixed> */
    public function setFeeConfig(bool $feeOnRecipient): array
    {
        return $this->client->request('/v1/payout/fee-config/set', ['fee_on_recipient' => $feeOnRecipient]);
    }

    /** @return array<string,mixed> */
    public function getRefundFeeConfig(): array
    {
        return $this->client->request('/v1/payout/refund-fee-config/get', []);
    }

    /** @return array<string,mixed> */
    public function setRefundFeeConfig(bool $feeOnCustomer): array
    {
        return $this->client->request('/v1/payout/refund-fee-config/set', ['fee_on_customer' => $feeOnCustomer]);
    }
}
