<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Управление вебхуками и тестовые события.
 *
 * Проверка входящих вебхуков — статический класс {@see \Oblodai\Webhooks}.
 */
final class WebhooksResource extends AbstractResource
{
    /**
     * Зарегистрировать endpoint для доставки вебхуков. POST /v1/webhooks
     *
     * ВАЖНО: Oblodai подписывает КАЖДУЮ доставку (в т.ч. per-invoice url_callback) секретом,
     * который вернётся здесь. Сохраните его и проверяйте им входящие вебхуки. Без регистрации
     * endpoint'а доставки не отправляются.
     *
     * @return array{endpoint_id:string,url:string,secret:string}
     */
    public function register(string $url): array
    {
        return $this->client->request('/v1/webhooks', ['url' => $url]);
    }

    /**
     * Журнал доставок. POST /v1/webhooks/deliveries
     *
     * @return array<string,mixed>
     */
    public function deliveries(): array
    {
        return $this->client->request('/v1/webhooks/deliveries', []);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public function testPayment(array $params): array
    {
        return $this->client->request('/v1/test-webhook/payment', $params);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public function testWallet(array $params): array
    {
        return $this->client->request('/v1/test-webhook/wallet', $params);
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public function testPayout(array $params): array
    {
        return $this->client->request('/v1/test-webhook/payout', $params);
    }
}
