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
     * Извлекает опциональный ключ идемпотентности из параметров вызова.
     *
     * С v1.1.0 защита от дублей при повторах — HTTP-заголовок Idempotency-Key
     * (см. Client::requestIdempotent): он генерируется один раз до цикла ретраев
     * и НЕ входит в тело/подпись. Параметр idempotency_key позволяет задать ключ
     * самому — он вынимается из тела и уходит заголовком. order_id больше НЕ
     * подставляется автоматически и передаётся как есть.
     *
     * @param array<string,mixed> $params
     *
     * @return array{0:array<string,mixed>,1:?string} [параметры без idempotency_key, ключ либо null]
     */
    protected function splitIdempotencyKey(array $params): array
    {
        $key = null;
        $v = $params['idempotency_key'] ?? null;
        if (is_string($v) && trim($v) !== '') {
            $key = $v;
        }
        unset($params['idempotency_key']);

        return [$params, $key];
    }
}
