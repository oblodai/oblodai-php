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
     * @return array<string,mixed>
     */
    public function blockedAddressRefund(string $uuid, string $address): array
    {
        return $this->client->request('/v1/wallet/blocked-address-refund', ['uuid' => $uuid, 'address' => $address]);
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
