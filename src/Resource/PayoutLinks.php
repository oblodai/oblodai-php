<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Payout-ссылки («крипто-чеки»): выплата получателю, кошелёк которого вы не знаете.
 *
 * Мерчант резервирует сумму в claimable-ссылку (available -> payout_held) и передаёт
 * получателю claim_url; тот вводит свой адрес — из резерва порождается обычная выплата.
 * Непорученная ссылка возвращает резерв при истечении срока или отмене.
 *
 * Management-методы требуют ключ выплат. claimInfo()/claim() — публичные (без подписи):
 * доступ управляется самим claim-токеном из ответа create().
 *
 * Идемпотентность: create()/createBatch() РЕЗЕРВИРУЮТ баланс, поэтому уходят с заголовком
 * Idempotency-Key (генерируется один раз до цикла ретраев). Оба маршрута обёрнуты в серверную
 * идемпотентность: повтор с тем же ключом реплеит ПЕРВЫЙ ответ целиком — ту же ссылку и тот же
 * claim_token — и помечается заголовком ответа Idempotent-Replayed: true; баланс дебетуется
 * ровно один раз. БЕЗ заголовка поведение прежнее: два одинаковых вызова создадут ДВЕ ссылки.
 * Свой ключ — параметром 'idempotency_key' у create() и аргументом $idempotencyKey у createBatch().
 *
 * Второй, независимый рубеж — опциональный per-link 'reference' (уникален в рамках мерчанта):
 * он дедуплицирует и там, где заголовка нет, и там, где реплей недоступен. Дубль reference —
 * 409 payoutlink.duplicate_reference (раньше был 500, который SDK ретраил; теперь терминален).
 * Частично упавшая пачка реплеится КАК ЕСТЬ — упавшие элементы под тем же ключом повторно
 * не обработаются; допинывайте их новым вызовом с новым ключом.
 *
 * Возможные ошибки идемпотентности: 400 idempotency.bad_key (кривой ключ), 400
 * idempotency.key_reused (тот же ключ с другим телом), 409 idempotency.in_progress
 * (первый запрос ещё выполняется), 503 idempotency.unavailable (стор недоступен, fail-closed).
 * SDK ретраит из них только 503; 400/409 терминальны.
 */
final class PayoutLinks extends AbstractResource
{
    /**
     * Создать payout-ссылку. POST /v1/payout/link
     *
     * ВАЖНО про срок жизни: задавайте 'expires_in_hours' ЯВНО (1..720). Если поле не задано
     * или равно 0, бэкенд клампит его к МИНИМУМУ — ссылка проживёт всего 1 час.
     *
     * claim_token/claim_url возвращаются ТОЛЬКО в ответе create — сохраните их сразу
     * (в list/info их больше не будет).
     *
     * Уходит с заголовком Idempotency-Key (стабилен между повторами: ретрай по потерянному
     * ответу реплеит первую ссылку с тем же claim_token, а не резервирует баланс второй раз);
     * свой ключ — параметром 'idempotency_key'. Без ключа два одинаковых вызова создадут ДВЕ
     * ссылки — задавайте 'reference' как durable-рубеж (дубль -> 409
     * payoutlink.duplicate_reference, терминальная ошибка).
     *
     * @param array<string,mixed> $params currency (крипто-актив), network, amount (строка),
     *                                     reference (ключ дедупа), title, note,
     *                                     email (получателю уйдёт claim-письмо),
     *                                     expires_in_hours (1..720 — задавайте явно!),
     *                                     idempotency_key (свой ключ; уйдёт заголовком)
     *
     * @return array<string,mixed> {link_id, status, amount, currency, network, expires_at,
     *                              created_at, claim_token, claim_url, ...}
     */
    public function create(array $params): array
    {
        [$params, $key] = $this->splitIdempotencyKey($params);

        return $this->client->requestIdempotent('/v1/payout/link', $params, $key);
    }

    /**
     * Пачка payout-ссылок (до 500 за вызов). POST /v1/payout/link/batch
     *
     * Каждый элемент резервируется в своей транзакции: плохой элемент фейлит только себя.
     * Все ссылки вызова получают общий batch_id. Ответ index-aligned:
     * {created, total, results: [{ok, link?|error?, message?}]}.
     *
     * Уходит с заголовком Idempotency-Key (стабилен между повторами). ВАЖНО: реплей отдаёт
     * ответ ПЕРВОЙ попытки как есть — упавшие элементы под тем же ключом не переобрабатываются;
     * допинывайте их отдельным вызовом с НОВЫМ ключом, а per-link 'reference' защитит от дублей.
     *
     * ВТОРАЯ ОГОВОРКА, важная именно для пачек: ответ больше 256 КБ бэкенд НЕ кэширует, и тогда
     * повтор с тем же ключом выполнится ЗАНОВО. Поэтому на батчах проставляйте per-item
     * 'reference' — в этом случае он остаётся единственным рубежом против дублей.
     *
     * @param array<int,array<string,mixed>> $links          элементы — тела обычного create()
     * @param string|null                    $idempotencyKey свой ключ; null — сгенерировать
     *
     * @return array<string,mixed> {created, total, results}
     */
    public function createBatch(array $links, ?string $idempotencyKey = null): array
    {
        return $this->client->requestIdempotent('/v1/payout/link/batch', ['links' => $links], $idempotencyKey);
    }

    /**
     * Список ссылок (created_at DESC, без claim_token). POST /v1/payout/link/list
     *
     * @return array<string,mixed> {links: [...]}
     */
    public function list(?int $limit = null, ?int $offset = null): array
    {
        $p = [];
        if ($limit !== null) {
            $p['limit'] = $limit;
        }
        if ($offset !== null) {
            $p['offset'] = $offset;
        }

        return $this->client->request('/v1/payout/link/list', $p);
    }

    /**
     * Детали ссылки (после claim — с payout_id и claim_address). POST /v1/payout/link/info
     *
     * @return array<string,mixed>
     */
    public function info(string $linkId): array
    {
        return $this->client->request('/v1/payout/link/info', ['link_id' => $linkId]);
    }

    /**
     * Отменить непорученную (funded) ссылку — резерв вернётся на available.
     * POST /v1/payout/link/cancel
     *
     * @return array<string,mixed> ссылка с новым статусом
     */
    public function cancel(string $linkId): array
    {
        return $this->client->request('/v1/payout/link/cancel', ['link_id' => $linkId]);
    }

    /**
     * Публичные детали для страницы claim (БЕЗ подписи). GET /v1/claim/{token}
     *
     * @param string $token claim-токен из ответа create()
     *
     * @return array<string,mixed> {status, amount, currency, network, title, note,
     *                              expires_at, claimable}
     */
    public function claimInfo(string $token): array
    {
        return $this->client->requestPublic('/v1/claim/' . rawurlencode($token), [], 'GET');
    }

    /**
     * Публичный claim (БЕЗ подписи): получатель фиксирует адрес, порождается выплата.
     * POST /v1/claim/{token}
     *
     * Повторный claim с тем же адресом идемпотентен (вернёт уже порождённую выплату);
     * с другим адресом — ошибка payoutlink.claim_in_progress.
     *
     * @param string      $address адрес получателя в сети ссылки
     * @param string|null $memo    dest tag / comment (TON и т.п.)
     *
     * @return array<string,mixed> {status, payout_id, amount, currency, network, address}
     */
    public function claim(string $token, string $address, ?string $memo = null): array
    {
        $p = ['address' => $address];
        if ($memo !== null && $memo !== '') {
            $p['memo'] = $memo;
        }

        return $this->client->requestPublic('/v1/claim/' . rawurlencode($token), $p);
    }
}
