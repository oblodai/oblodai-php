<?php

declare(strict_types=1);

namespace Oblodai\Resource;

/**
 * Автовывод и IP-allowlist (требуют ключ выплат).
 */
final class Settings extends AbstractResource
{
    /** @return array<string,mixed> */
    public function listAutoWithdraw(): array
    {
        return $this->client->request('/v1/auto-withdraw/list', []);
    }

    /**
     * @param array<string,mixed> $params currency, network, address, min
     *
     * @return array<string,mixed>
     */
    public function setAutoWithdraw(array $params): array
    {
        return $this->client->request('/v1/auto-withdraw/set', $params);
    }

    /** @return array<string,mixed> */
    public function deleteAutoWithdraw(string $currency): array
    {
        return $this->client->request('/v1/auto-withdraw/delete', ['currency' => $currency]);
    }

    /** @return array<string,mixed> */
    public function listAllowlist(): array
    {
        return $this->client->request('/v1/api-allowlist/list', []);
    }

    /** @return array<string,mixed> */
    public function addAllowlist(string $cidr): array
    {
        return $this->client->request('/v1/api-allowlist/add', ['cidr' => $cidr]);
    }

    /** @return array<string,mixed> */
    public function removeAllowlist(string $cidr): array
    {
        return $this->client->request('/v1/api-allowlist/remove', ['cidr' => $cidr]);
    }

    /** @return array<string,mixed> */
    public function enableAllowlist(bool $enabled): array
    {
        return $this->client->request('/v1/api-allowlist/enable', ['enabled' => $enabled]);
    }
}
