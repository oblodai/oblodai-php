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
     * ВАЖНО: Oblodai подписывает КАЖДУЮ доставку (в т.ч. per-invoice url_callback) ОТДЕЛЬНЫМ
     * секретом ЭНДПОИНТА, который вернётся здесь. Это НЕ секрет API-ключа: подставив в проверку
     * подписи ключ API, вы отвергнете 100% вебхуков. Сохраните `secret` из ответа. Без
     * регистрации endpoint'а доставки не отправляются вовсе.
     *
     * ⚠ ЭНДПОИНТ НА ПРОЕКТ РОВНО ОДИН, и это UPSERT, а не «добавить ещё один».
     * В ядре: `INSERT ... ON CONFLICT (project_id) DO UPDATE SET url = EXCLUDED.url`. Поэтому
     * повторный вызов с ДРУГИМ url не создаёт второй endpoint, а ПЕРЕНАПРАВЛЯЕТ доставки:
     * возвращается тот же `endpoint_id`, а старый URL молча замолкает. Типичная авария —
     * «зарегистрировали staging-URL из локального скрипта и потеряли прод-вебхуки».
     * Секрет при перерегистрации СОХРАНЯЕТСЯ (уже поставленные в очередь доставки подписаны
     * им и иначе бы протухли) — ответ вернёт тот же `secret`, что и в первый раз.
     *
     * @return array{endpoint_id:string,url:string,secret:string}
     */
    public function register(string $url): array
    {
        return $this->client->request('/v1/webhooks', ['url' => $url]);
    }

    /**
     * Журнал последних доставок (до 50, новые первыми). POST /v1/webhooks/deliveries
     *
     * ⚠ ЛОМАЮЩЕЕ изменение в v1.2.0: метод отдаёт СПИСОК доставок, а не конверт
     * `['deliveries' => [...]]`. Раньше он был единственным местом в SDK, где конверт
     * приходилось разворачивать вручную (`sandbox()->listWebhooks()` уже отдавал список);
     * теперь форма одна во всех SDK.
     *
     * @return array<int,array<string,mixed>> элементы: {id, url, event_type, status, attempts,
     *                                       last_error, created_at, updated_at}
     */
    public function deliveries(): array
    {
        $res = $this->client->request('/v1/webhooks/deliveries', []);

        return is_array($res) && isset($res['deliveries']) && is_array($res['deliveries'])
            ? $res['deliveries']
            : [];
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
