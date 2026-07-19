<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Приём платежей и настройки приёма.
 */
final class Payments extends AbstractResource
{
    /**
     * Создать счёт. POST /v1/payment
     *
     * Идемпотентность: SDK шлёт заголовок Idempotency-Key (один и тот же во всех внутренних
     * повторах). order_id уходит как есть и НЕ подставляется автоматически (ломающее изменение
     * v1.1.0). Свой ключ — параметром 'idempotency_key' (уйдёт в заголовок, не в тело).
     *
     * @param array<string,mixed> $params amount, currency, order_id, network, to_currency, lifetime,
     *                                     subtract, url_callback, url_return, url_success, is_payment_multiple,
     *                                     idempotency_key, ...
     *
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        [$params, $key] = $this->splitIdempotencyKey($params);

        return $this->client->requestIdempotent('/v1/payment', $params, $key);
    }

    /**
     * Пачка платежей (до 5000 одним запросом, обработка в фоне). POST /v1/payment/batch
     *
     * order_id обязателен на каждом элементе (дедуп внутри пачки). Возвращает batch_id —
     * прогресс и результаты забираются через $client->batches()->info(). Запрос уходит
     * с заголовком Idempotency-Key (стабилен между повторами).
     *
     * @param array<int,array<string,mixed>> $payments элементы — тела обычного create()
     * @param string                         $onError  'continue' (по умолчанию) или 'stop'
     *
     * @return array<string,mixed> {batch_id, kind, count, status}
     */
    public function createBatch(array $payments, string $onError = 'continue', ?string $idempotencyKey = null): array
    {
        return $this->client->requestIdempotent(
            '/v1/payment/batch',
            ['payments' => $payments, 'on_error' => $onError],
            $idempotencyKey,
        );
    }

    /**
     * Пачка возвратов (до 5000). POST /v1/refund/batch (требует ключ выплат).
     *
     * На каждом элементе обязательны 'reference' И 'uuid'/'order_id' инвойса
     * (дедуп-скоуп — пара инвойс+reference). Уходит с заголовком Idempotency-Key.
     *
     * @param array<int,array<string,mixed>> $refunds элементы — тела обычного refund()
     * @param string                         $onError 'continue' (по умолчанию) или 'stop'
     *
     * @return array<string,mixed> {batch_id, kind, count, status}
     */
    public function refundBatch(array $refunds, string $onError = 'continue', ?string $idempotencyKey = null): array
    {
        return $this->client->requestIdempotent(
            '/v1/refund/batch',
            ['refunds' => $refunds, 'on_error' => $onError],
            $idempotencyKey,
        );
    }

    /**
     * Отправить счёт на e-mail (письмо с кнопкой «Оплатить»). POST /v1/payment/send-email
     *
     * Получатель — $email либо payer_email платежа. Лимит: 10 писем/час на адрес получателя.
     *
     * @return array<string,mixed> {sent, email, uuid}
     */
    public function sendEmail(?string $uuid = null, ?string $orderId = null, ?string $email = null): array
    {
        $p = $this->lookup($uuid, $orderId);
        if ($email !== null && $email !== '') {
            $p['email'] = $email;
        }

        return $this->client->request('/v1/payment/send-email', $p);
    }

    /**
     * Решение судьбы недоплаченного платежа (статус wrong_amount). POST /v1/payment/resolve
     * (требует ключ выплат).
     *
     * action=accept — оставить частичную оплату себе (глушит авто-возврат);
     * action=refund — вернуть плательщику (address/network по умолчанию — плательщика;
     * для UTXO-сетей address обязателен, опционально reference — ключ дедупа возврата).
     * Уходит с заголовком Idempotency-Key; свой ключ — параметром 'idempotency_key'.
     *
     * @param array<string,mixed> $params uuid|order_id, action ('accept'|'refund'),
     *                                     address, network, reference, idempotency_key
     *
     * @return array<string,mixed>
     */
    public function resolve(array $params): array
    {
        [$params, $key] = $this->splitIdempotencyKey($params);

        return $this->client->requestIdempotent('/v1/payment/resolve', $params, $key);
    }

    /**
     * Информация о счёте (по uuid или order_id). POST /v1/payment/info
     *
     * @return array<string,mixed>
     */
    public function info(?string $uuid = null, ?string $orderId = null): array
    {
        return $this->client->request('/v1/payment/info', $this->lookup($uuid, $orderId));
    }

    /**
     * История платежей. POST /v1/payment/history
     *
     * @param array<string,mixed> $params limit, offset, status
     *
     * @return array<string,mixed>
     */
    public function history(array $params = []): array
    {
        return $this->client->request('/v1/payment/history', $params);
    }

    /**
     * Доступные методы приёма. POST /v1/payment/services
     *
     * @return array<int,array<string,mixed>>
     */
    public function services(): array
    {
        return $this->client->request('/v1/payment/services', []);
    }

    /**
     * QR депозит-адреса счёта. POST /v1/payment/qr
     *
     * @return array<string,mixed>
     */
    public function qr(?string $uuid = null, ?string $orderId = null): array
    {
        return $this->client->request('/v1/payment/qr', $this->lookup($uuid, $orderId));
    }

    /**
     * QR произвольного адреса. POST /v1/wallet/qr
     *
     * @return array<string,mixed>
     */
    public function walletQr(string $address): array
    {
        return $this->client->request('/v1/wallet/qr', ['address' => $address]);
    }

    /**
     * Переотправить вебхук платежа. POST /v1/payment/resend
     *
     * @return array<string,mixed>
     */
    public function resend(?string $uuid = null, ?string $orderId = null): array
    {
        return $this->client->request('/v1/payment/resend', $this->lookup($uuid, $orderId));
    }

    /**
     * Возврат платежа. POST /v1/payment/refund (требует ключ выплат).
     *
     * Уходит с заголовком Idempotency-Key (стабилен между повторами);
     * свой ключ — параметром 'idempotency_key'.
     *
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public function refund(array $params): array
    {
        [$params, $key] = $this->splitIdempotencyKey($params);

        return $this->client->requestIdempotent('/v1/payment/refund', $params, $key);
    }

    /** @return array<int,array<string,mixed>> */
    public function listAccepted(): array
    {
        return $this->client->request('/v1/payment/accepted/list', []);
    }

    /**
     * @param array<int,array{currency:string,network:string}> $accepted
     *
     * @return array<string,mixed>
     */
    public function setAccepted(array $accepted): array
    {
        return $this->client->request('/v1/payment/accepted/set', ['accepted' => $accepted]);
    }

    /** @return array<string,mixed> */
    public function getAccuracy(): array
    {
        return $this->client->request('/v1/payment/accuracy/get', []);
    }

    /** @return array<string,mixed> */
    public function setAccuracy(bool $enabled, ?float $accuracyPercent = null): array
    {
        $p = ['enabled' => $enabled];
        if ($accuracyPercent !== null) {
            $p['accuracy_percent'] = $accuracyPercent;
        }

        return $this->client->request('/v1/payment/accuracy/set', $p);
    }

    /** @return array<string,mixed> */
    public function getAutorefund(): array
    {
        return $this->client->request('/v1/payment/autorefund/get', []);
    }

    /** @return array<string,mixed> */
    public function setAutorefund(bool $overpay, bool $underpay): array
    {
        return $this->client->request('/v1/payment/autorefund/set', ['overpay' => $overpay, 'underpay' => $underpay]);
    }

    /** @return array<int,array<string,mixed>> */
    public function listDiscounts(): array
    {
        return $this->client->request('/v1/payment/discount/list', []);
    }

    /** @return array<string,mixed> */
    public function setDiscount(string $currency, string $network, int $discountPercent): array
    {
        return $this->client->request('/v1/payment/discount/set', [
            'currency' => $currency,
            'network' => $network,
            'discount_percent' => $discountPercent,
        ]);
    }

    /**
     * Публичное состояние счёта (без подписи). GET /v1/pay/{id}
     *
     * Для собственных checkout-страниц: адрес, сумма, статус, оставшееся время —
     * без API-ключа на фронте (тот же механизм, что publicGet/claimInfo у ссылок).
     *
     * @return array<string,mixed>
     */
    public function publicGet(string $uuid): array
    {
        return $this->client->requestPublic('/v1/pay/' . rawurlencode($uuid), [], 'GET');
    }

    /**
     * Публичный выбор валюты/сети для счёта (без подписи). POST /v1/pay/{id}/select
     *
     * Финализирует отложенный (валюто-агностичный) счёт: покупатель выбирает, чем
     * платить; ответ — обычный результат платежа (address, payment_status, ...).
     *
     * @return array<string,mixed>
     */
    public function publicSelect(string $uuid, string $currency, string $network): array
    {
        return $this->client->requestPublic(
            '/v1/pay/' . rawurlencode($uuid) . '/select',
            ['currency' => $currency, 'network' => $network],
        );
    }

    /**
     * @return array<string,string>
     */
    private function lookup(?string $uuid, ?string $orderId): array
    {
        $out = [];
        if ($uuid !== null) {
            $out['uuid'] = $uuid;
        }
        if ($orderId !== null) {
            $out['order_id'] = $orderId;
        }

        return $out;
    }
}
