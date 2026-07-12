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
     * @param array<string,mixed> $params amount, currency, order_id, network, to_currency, lifetime,
     *                                     subtract, url_callback, url_return, url_success, is_payment_multiple, ...
     *
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('/v1/payment', $params);
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
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public function refund(array $params): array
    {
        return $this->client->request('/v1/payment/refund', $params);
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
