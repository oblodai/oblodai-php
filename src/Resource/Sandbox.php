<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Developer sandbox: тестовые хелперы, заменяющие «покупатель заплатил on-chain».
 *
 * Все бизнес-эндпоинты работают с тестовыми ключами БЕЗ изменений — интеграционный код
 * между test и live не меняется, меняется только ключ (public id `test_…`, секрет
 * `oblodai_test_…`). Эти пять методов существуют ТОЛЬКО в песочнице: live-ключ получает
 * HTTP 403 `sandbox.live_key`. Не зовите их из продакшен-кода.
 */
final class Sandbox extends AbstractResource
{
    /**
     * Симулировать on-chain депозит в счёт. POST /v1/sandbox/deposit
     *
     * Опции:
     *  - amount        — строка; без неё платится ровно сумма счёта, иначе недо-/переплата;
     *  - confirmations — int; 0/не задано = сразу подтверждён, малое значение = «зависший»
     *                    депозит; повтор с ТЕМ ЖЕ txid и бОльшим значением «углубляет» его;
     *  - txid          — строка; не задан = новый; переиспользуйте для идемпотентности/углубления.
     *
     * Счёт с недобором подтверждений сам НЕ дозревает: симулированный депозит никто не переэмитит
     * и курсор для него не двигается — счёт висит в confirm_check, пока вы не повторите вызов с
     * ТЕМ ЖЕ txid и бОльшим confirmations. Не путайте с maturity-холдом на ВЫПЛАТЕ (ошибка
     * payout.funds_maturing): вот он в песочнице снимается по возрасту (по умолчанию ~10 минут,
     * GATEWAY_SANDBOX_MATURITY_MINUTES) — но это про баланс, а не про подтверждения счёта.
     * Достаточно глубокий повтор снимает и его сразу.
     *
     * @param array{amount?:string,confirmations?:int,txid?:string} $opts
     *
     * @return array<string,mixed> {invoice_id, txid, amount, confirmations}
     */
    public function simulateDeposit(string $invoiceId, array $opts = []): array
    {
        return $this->client->request('/v1/sandbox/deposit', ['invoice_id' => $invoiceId] + $opts);
    }

    /**
     * Начислить тестовый баланс «из воздуха». POST /v1/sandbox/faucet
     *
     * Лимит: не более 1000000 за вызов. idempotency_key (в теле) защищает от дублей.
     *
     * @return array<string,mixed> {asset, amount, journal_id}
     */
    public function faucet(string $asset, string $amount, ?string $idempotencyKey = null): array
    {
        $p = ['asset' => $asset, 'amount' => $amount];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $p['idempotency_key'] = $idempotencyKey;
        }

        return $this->client->request('/v1/sandbox/faucet', $p);
    }

    /**
     * Сбросить песочницу: отменить НЕОПЛАЧИВАЕМЫЕ счета, обнулить балансы. POST /v1/sandbox/reset
     *
     * Это НЕ «чистый лист». Отменяются только счета в статусах `created` (API: `check`) и
     * `select` — те, по которым депозита ещё не видели. Счёт, по которому депозит уже виден
     * (`confirm_check`, `wrong_amount_waiting`), СОЗНАТЕЛЬНО не трогается: отмена дала бы
     * депозиту подтвердиться в отменённый счёт и зачислить деньги без события. Песочница
     * не обходит это правило — симулированный депозит для пайплайна ничем не отличается от
     * настоящего (см. комментарий в ядре, internal/app/sandboxapi/reset.go).
     *
     * Ничего не удаляется: леджер append-only, обнуление баланса — компенсирующая проводка,
     * поэтому история экспериментов остаётся читаемой. Если нужен действительно чистый счёт —
     * создайте новый, а не рассчитывайте, что reset уберёт «зависший» в confirm_check.
     *
     * @return array<string,mixed> {invoices_cancelled, balances_zeroed}
     */
    public function reset(): array
    {
        return $this->client->request('/v1/sandbox/reset', []);
    }

    /**
     * Недавние доставки вебхуков (до 50, новые первыми). GET /v1/sandbox/webhooks
     *
     * Подписанный GET с пустым телом (подпись над "{ts}\nGET\n/v1/sandbox/webhooks\n").
     *
     * @return array<int,array<string,mixed>> элементы: {id, event_type, url, status, attempts,
     *                                        last_error, payload (сырой JSON), created_at, updated_at}
     */
    public function listWebhooks(): array
    {
        $res = $this->client->requestGet('/v1/sandbox/webhooks');

        return is_array($res) && isset($res['deliveries']) && is_array($res['deliveries'])
            ? $res['deliveries']
            : [];
    }

    /**
     * Поставить доставку вебхука в очередь заново. POST /v1/sandbox/webhooks/replay
     *
     * @return array<string,mixed> {delivery_id, requeued}
     */
    public function replayWebhook(string $deliveryId): array
    {
        return $this->client->request('/v1/sandbox/webhooks/replay', ['delivery_id' => $deliveryId]);
    }
}
