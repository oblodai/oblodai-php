<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Model\FaucetResult;
use Oblodai\Contract\Model\SandboxDeposit;
use Oblodai\Contract\Model\SandboxReplay;
use Oblodai\Contract\Model\SandboxReset;
use Oblodai\Contract\Model\WebhookDelivery;
use Oblodai\Contract\Request\SandboxDepositRequest;
use Oblodai\Contract\Request\SandboxFaucetRequest;
use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;

/** Developer sandbox (`test_oblodai_…` keys only): fake money, simulated deposits, webhook inspector. */
final class Sandbox extends Resource
{
    /**
     * `POST /v1/sandbox/faucet` — credit test funds.
     *
     * @param array<string, mixed>|SandboxFaucetRequest $params
     */
    public function faucet(array|SandboxFaucetRequest $params, ?RequestOptions $options = null): FaucetResult
    {
        return $this->call('POST /v1/sandbox/faucet', $params, $options, FaucetResult::fromArray(...));
    }

    /**
     * `POST /v1/sandbox/deposit` — simulate an on-chain deposit to an invoice (repeat the txid to
     * add confirmations).
     *
     * @param array<string, mixed>|SandboxDepositRequest $params
     */
    public function deposit(
        array|SandboxDepositRequest $params,
        ?RequestOptions $options = null,
    ): SandboxDeposit {
        return $this->call('POST /v1/sandbox/deposit', $params, $options, SandboxDeposit::fromArray(...));
    }

    /**
     * `GET /v1/sandbox/webhooks` — deliveries with their payloads.
     *
     * @param  array<string, mixed> $params limit/offset
     * @return Page<WebhookDelivery>
     */
    public function webhooks(array $params = [], ?RequestOptions $options = null): Page
    {
        return $this->page('GET /v1/sandbox/webhooks', $params, WebhookDelivery::fromArray(...), $options);
    }

    /** `POST /v1/sandbox/webhooks/replay` — re-send a terminal (delivered/dead) delivery. */
    public function replay(string $deliveryId, ?RequestOptions $options = null): SandboxReplay
    {
        return $this->call(
            'POST /v1/sandbox/webhooks/replay',
            ['delivery_id' => $deliveryId],
            $options,
            SandboxReplay::fromArray(...)
        );
    }

    /** `POST /v1/sandbox/reset` — cancel open invoices and zero balances. */
    public function reset(?RequestOptions $options = null): SandboxReset
    {
        return $this->call('POST /v1/sandbox/reset', null, $options, SandboxReset::fromArray(...));
    }
}
