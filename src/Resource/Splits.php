<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Сплит-платежи: доля каждого входящего платежа автоматически уходит партнёру —
 * на внешний адрес (необратимо) или аккаунту на платформе (обратимо).
 *
 * Все методы требуют ключ выплат.
 */
final class Splits extends AbstractResource
{
    /**
     * Правило: доля на внешний адрес (необратимо). POST /v1/split/rule
     *
     * @param float $percent доля в процентах, шаг 0.01 (0 < percent <= 100;
     *                        сумма активных правил тоже не может превышать 100)
     *
     * @return array<string,mixed> {rule_id, percent}
     */
    public function splitToAddress(string $address, string $network, float $percent, ?string $note = null): array
    {
        $p = ['address' => $address, 'network' => $network, 'percent' => $percent];
        if ($note !== null) {
            $p['note'] = $note;
        }

        return $this->client->request('/v1/split/rule', $p);
    }

    /**
     * Правило: доля аккаунту на платформе (обратимо: возврат отзовёт долю). POST /v1/split/rule
     *
     * @return array<string,mixed> {rule_id, percent}
     */
    public function splitToMerchant(string $merchantId, float $percent, ?string $note = null): array
    {
        $p = ['merchant_id' => $merchantId, 'percent' => $percent];
        if ($note !== null) {
            $p['note'] = $note;
        }

        return $this->client->request('/v1/split/rule', $p);
    }

    /**
     * Создать правило «сырыми» параметрами (низкоуровневый вариант splitToAddress/splitToMerchant).
     * POST /v1/split/rule
     *
     * @param array<string,mixed> $params {address, network} ИЛИ {merchant_id} + percent, note
     *
     * @return array<string,mixed> {rule_id, percent}
     */
    public function createRule(array $params): array
    {
        return $this->client->request('/v1/split/rule', $params);
    }

    /**
     * Список правил. POST /v1/split/rule/list
     *
     * @return array<string,mixed> {items: [{rule_id, percent, active, note, reversible, ...}]}
     */
    public function listRules(): array
    {
        return $this->client->request('/v1/split/rule/list', []);
    }

    /**
     * Удалить правило. POST /v1/split/rule/delete
     *
     * @return array<string,mixed> {deleted: true}
     */
    public function deleteRule(string $ruleId): array
    {
        return $this->client->request('/v1/split/rule/delete', ['rule_id' => $ruleId]);
    }

    /**
     * Настройки сплитов. POST /v1/split/config/get
     *
     * @return array<string,mixed> {refund_hold_hours}
     */
    public function getConfig(): array
    {
        return $this->client->request('/v1/split/config/get', []);
    }

    /**
     * Задать окно удержания перед отправкой долей (часы). POST /v1/split/config/set
     *
     * @return array<string,mixed>
     */
    public function setConfig(int $refundHoldHours): array
    {
        return $this->client->request('/v1/split/config/set', ['refund_hold_hours' => $refundHoldHours]);
    }
}
