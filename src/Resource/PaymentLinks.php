<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Платёжные ссылки: переиспользуемая ссылка, по которой платят много людей;
 * каждый платёж — отдельный инвойс со своим адресом.
 */
final class PaymentLinks extends AbstractResource
{
    /**
     * Создать платёжную ссылку. POST /v1/payment/link
     *
     * Заголовок Idempotency-Key на этот эндпоинт не шлётся (создание ссылки не двигает деньги).
     *
     * @param array<string,mixed> $params title, description, amount_mode ('fixed'|'open'|'range'),
     *                                     currency, amount_fixed, amount_min, amount_max,
     *                                     pinned_currency, pinned_network,
     *                                     expires_in (секунды; 0 — бессрочно)
     *
     * @return array<string,mixed> {link_id, url}
     */
    public function create(array $params): array
    {
        return $this->client->request('/v1/payment/link', $params);
    }

    /**
     * Список ссылок (created_at DESC). POST /v1/payment/link/list
     *
     * @return array<string,mixed> {items: [...]}
     */
    public function list(?int $limit = null, ?int $offset = null): array
    {
        return $this->client->request('/v1/payment/link/list', $this->page($limit, $offset));
    }

    /**
     * Детали ссылки + платежи по ней. POST /v1/payment/link/info
     *
     * @return array<string,mixed>
     */
    public function info(string $linkId): array
    {
        return $this->client->request('/v1/payment/link/info', ['link_id' => $linkId]);
    }

    /**
     * Включить/выключить ссылку. POST /v1/payment/link/toggle
     *
     * @return array<string,mixed> {link_id, active}
     */
    public function toggle(string $linkId, bool $active): array
    {
        return $this->client->request('/v1/payment/link/toggle', ['link_id' => $linkId, 'active' => $active]);
    }

    /**
     * Публичные детали ссылки (без подписи). GET /v1/link/{id}
     *
     * @return array<string,mixed>
     */
    public function publicGet(string $linkId): array
    {
        return $this->client->requestPublic('/v1/link/' . rawurlencode($linkId), [], 'GET');
    }

    /**
     * Публичный checkout по ссылке (без подписи): создаёт инвойс. POST /v1/link/{id}/checkout
     *
     * Лимит бэкенда: 30 инвойсов/мин на ссылку (paylink.rate_limited).
     *
     * @param array<string,mixed> $params amount, currency, network, payer_email
     *                                     (закреплённые в ссылке валюта/сеть побеждают)
     *
     * @return array<string,mixed> ответ обычного создания платежа (uuid, url, ...)
     */
    public function checkout(string $linkId, array $params = []): array
    {
        return $this->client->requestPublic('/v1/link/' . rawurlencode($linkId) . '/checkout', $params);
    }

    /**
     * @return array<string,int>
     */
    private function page(?int $limit, ?int $offset): array
    {
        $p = [];
        if ($limit !== null) {
            $p['limit'] = $limit;
        }
        if ($offset !== null) {
            $p['offset'] = $offset;
        }

        return $p;
    }
}
