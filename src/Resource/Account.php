<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Баланс, рефералы, переводы (личный кошелёк, пользователи платформы), VRCS.
 */
final class Account extends AbstractResource
{
    /**
     * Баланс мерчанта. POST /v1/balance
     *
     * @return array<string,mixed>
     */
    public function balance(): array
    {
        return $this->client->request('/v1/balance', []);
    }

    /**
     * Реферальная статистика. POST /v1/referral/info
     *
     * @return array<string,mixed>
     */
    public function referral(): array
    {
        return $this->client->request('/v1/referral/info', []);
    }

    /**
     * Перевод на личный кошелёк. POST /v1/transfer/to-personal (ключ выплат).
     *
     * Уходит с заголовком Idempotency-Key (стабилен между повторами). order_id
     * НЕ подставляется автоматически (ломающее изменение v1.1.0) — передаётся как есть.
     * Свой ключ — параметром 'idempotency_key'.
     *
     * @param array<string,mixed> $params amount, currency, order_id, idempotency_key
     *
     * @return array<string,mixed>
     */
    public function transferToPersonal(array $params): array
    {
        [$params, $key] = $this->splitIdempotencyKey($params);

        return $this->client->requestIdempotent('/v1/transfer/to-personal', $params, $key);
    }

    /**
     * Перевод пользователю платформы. POST /v1/transfer/to-user (ключ выплат).
     *
     * Внутренний перевод БЕЗ комиссии с баланса мерчанта на личный кошелёк
     * пользователя платформы Oblodai. to_user_id — UUID пользователя платформы
     * (НЕ юзернейм). Уходит с заголовком Idempotency-Key (стабилен между
     * повторами); свой ключ — параметром 'idempotency_key'.
     *
     * @param array<string,mixed> $params to_user_id, amount, currency, order_id (опц.), idempotency_key
     *
     * @return array<string,mixed> {currency, amount, to_user_id, recipient_balance}
     */
    public function transferToUser(array $params): array
    {
        [$params, $key] = $this->splitIdempotencyKey($params);

        return $this->client->requestIdempotent('/v1/transfer/to-user', $params, $key);
    }

    /**
     * Пачка переводов пользователям (обработка в фоне). POST /v1/transfer/batch (ключ выплат).
     *
     * Элементы — тела обычного transferToUser(). Возвращает batch_id — прогресс и
     * результаты через $client->batches()->info(). Уходит с заголовком
     * Idempotency-Key (стабилен между повторами).
     *
     * @param array<int,array<string,mixed>> $transfers элементы — тела transferToUser()
     * @param string                         $onError   'continue' (по умолчанию) или 'stop'
     *
     * @return array<string,mixed> {batch_id, kind, count, status}
     */
    public function transferBatch(array $transfers, string $onError = 'continue', ?string $idempotencyKey = null): array
    {
        return $this->client->requestIdempotent(
            '/v1/transfer/batch',
            ['transfers' => $transfers, 'on_error' => $onError],
            $idempotencyKey,
        );
    }

    /**
     * Авто-конвертация в USDT (VRCS). POST /v1/vrcs
     *
     * @return array<string,mixed>
     */
    public function vrcs(?bool $enabled = null): array
    {
        $p = [];
        if ($enabled !== null) {
            $p['enabled'] = $enabled;
        }

        return $this->client->request('/v1/vrcs', $p);
    }
}
