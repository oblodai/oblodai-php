<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\MerchantOnboarded;
use Oblodai\Contract\Model\SandboxStore;
use Oblodai\Contract\Request\MerchantsRequest;
use Oblodai\Core\RequestOptions;

/**
 * Merchant provisioning — for platforms that onboard merchants themselves. These routes are not
 * HMAC-signed; a self-hosted gateway gates them with its admin token (`adminToken` option).
 */
final class Merchants extends Resource
{
    /**
     * `POST /v1/merchants` — create a merchant and mint its payment and payout keys (shown once).
     *
     * @param array<string, mixed>|MerchantsRequest $params
     */
    public function create(array|MerchantsRequest $params, ?RequestOptions $options = null): MerchantOnboarded
    {
        return $this->call('POST /v1/merchants', $params, $options, MerchantOnboarded::fromArray(...));
    }

    /** `POST /v1/merchants/{id}/sandbox` — the merchant's dev store and its `test_` key (idempotent). */
    public function createSandbox(string $merchantId, ?RequestOptions $options = null): SandboxStore
    {
        return $this->call(
            'POST /v1/merchants/{id}/sandbox',
            null,
            $options,
            SandboxStore::fromArray(...),
            ['id' => $merchantId]
        );
    }
}
