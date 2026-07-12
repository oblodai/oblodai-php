<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Client;

abstract class AbstractResource
{
    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Гарантирует стабильный order_id для идемпотентности повторов.
     *
     * Бэкенд дедуплицирует платежи/переводы по order_id, а клиент повторяет
     * неидемпотентные POST при сетевых/5xx-сбоях. Ключ генерируется единожды
     * (если не задан вызывающим), поэтому во всех попытках уходит ОДИН и тот же
     * order_id и повтор не создаёт дубль.
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    protected function withIdempotencyKey(array $params): array
    {
        if (!isset($params['order_id']) || $params['order_id'] === '') {
            $params['order_id'] = 'idem-' . bin2hex(random_bytes(16));
        }

        return $params;
    }
}
