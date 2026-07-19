<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Статические кошельки.
 */
final class Wallets extends AbstractResource
{
    /**
     * Создать статический кошелёк. POST /v1/wallet
     *
     * @param array<string,mixed> $params currency, network, order_id
     *
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('/v1/wallet', $params);
    }

    /**
     * Блокировка кошелька. POST /v1/wallet/block
     *
     * @return array<string,mixed>
     */
    public function block(string $address, ?bool $isForceBlock = null): array
    {
        $p = ['address' => $address];
        if ($isForceBlock !== null) {
            $p['is_force_block'] = $isForceBlock;
        }

        return $this->client->request('/v1/wallet/block', $p);
    }

    /**
     * Возврат с заблокированного кошелька. POST /v1/wallet/blocked-address-refund (ключ выплат).
     *
     * Создаёт выплату, поэтому уходит с заголовком Idempotency-Key (стабилен между внутренними
     * повторами). При этом маршрут НАМЕРЕННО не обёрнут в серверную идемпотентность — и это не
     * пробел: защита здесь безусловная и от заголовка не зависит. Эндпоинт once-only ПО КОШЕЛЬКУ —
     * бэкенд выводит стабильный reference ("refund-wallet:<walletID>") из id кошелька, берёт
     * per-wallet advisory lock и внутри лока сначала ищет уже существующую выплату, так что
     * повтор (в том числе конкурентный, в том числе без заголовка) возвращает ПЕРВУЮ выплату,
     * а не создаёт вторую. Обёртка middleware была бы здесь регрессом: конкурентный повтор
     * получал бы 409 idempotency.in_progress вместо ожидания и успеха.
     *
     * Оговорка: адрес в reference не участвует, поэтому повтор с ДРУГИМ адресом вернёт первую
     * выплату на ПЕРВЫЙ адрес.
     *
     * @param string|null $idempotencyKey свой ключ; null — сгенерировать
     *
     * @return array<string,mixed>
     */
    public function blockedAddressRefund(string $uuid, string $address, ?string $idempotencyKey = null): array
    {
        return $this->client->requestIdempotent(
            '/v1/wallet/blocked-address-refund',
            ['uuid' => $uuid, 'address' => $address],
            $idempotencyKey,
        );
    }

    /**
     * QR произвольного адреса. POST /v1/wallet/qr
     *
     * @return array<string,mixed>
     */
    public function qr(string $address): array
    {
        return $this->client->request('/v1/wallet/qr', ['address' => $address]);
    }
}
