<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Баланс, рефералы, перевод на личный кошелёк, VRCS.
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
