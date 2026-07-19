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
     * order_id обязателен (задаётся вами). Уходит с заголовком Idempotency-Key
     * (стабилен между повторами); свой ключ — параметром 'idempotency_key'.
     *
     * @param array<string,mixed> $params amount, currency, order_id, address, network, idempotency_key, ...
     *
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        [$params, $key] = $this->splitIdempotencyKey($params);

        return $this->client->requestIdempotent('/v1/payout', $params, $key);
    }

    /**
     * Массовая выплата. POST /v1/payout/mass
     *
     * Уходит с заголовком Idempotency-Key (стабилен между повторами).
     *
     * @param array<int,array<string,mixed>> $payouts
     *
     * @return array<string,mixed>
     */
    public function createMass(array $payouts, ?string $source = null, ?string $idempotencyKey = null): array
    {
        $p = ['payouts' => $payouts];
        if ($source !== null) {
            $p['source'] = $source;
        }

        return $this->client->requestIdempotent('/v1/payout/mass', $p, $idempotencyKey);
    }

    /**
     * Пачка выплат (до 5000 одним запросом, обработка в фоне). POST /v1/payout/batch
     *
     * order_id обязателен на каждом элементе (дедуп внутри пачки). Возвращает batch_id —
     * прогресс и результаты через $client->batches()->info(). Уходит с заголовком
     * Idempotency-Key (стабилен между повторами).
     *
     * @param array<int,array<string,mixed>> $payouts элементы — тела обычного create()
     * @param string                         $onError 'continue' (по умолчанию) или 'stop'
     *
     * @return array<string,mixed> {batch_id, kind, count, status}
     */
    public function createBatch(array $payouts, string $onError = 'continue', ?string $idempotencyKey = null): array
    {
        return $this->client->requestIdempotent(
            '/v1/payout/batch',
            ['payouts' => $payouts, 'on_error' => $onError],
            $idempotencyKey,
        );
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

    /**
     * Одобрить выплату, ждущую подтверждения. POST /v1/payout/approve
     *
     * Ключ идемпотентности здесь НЕ нужен и намеренно не шлётся: это переход состояния, сервер
     * принимает только выплату в статусе pending и иначе отвечает 409 payout.not_pending.
     * Повторный approve физически не может одобрить или сдвинуть деньги дважды — читайте этот
     * 409 как «уже одобрено» и уточняйте фактический статус через info().
     *
     * @return array<string,mixed>
     */
    public function approve(string $uuid): array
    {
        return $this->client->request('/v1/payout/approve', ['uuid' => $uuid]);
    }

    /**
     * Возврат платежа. POST /v1/payment/refund (тот же метод, что и Payments::refund).
     *
     * Уходит с заголовком Idempotency-Key; свой ключ — параметром 'idempotency_key'.
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public function refund(array $params): array
    {
        [$params, $key] = $this->splitIdempotencyKey($params);

        return $this->client->requestIdempotent('/v1/payment/refund', $params, $key);
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
