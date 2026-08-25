<?php

declare(strict_types=1);

namespace Oblodai\Resource;

use Oblodai\Contract\Enum\WebhookKind;
use Oblodai\Contract\Model\WebhookDelivery;
use Oblodai\Contract\Model\WebhookEndpoint;
use Oblodai\Contract\Model\WebhookSecretRotated;
use Oblodai\Contract\Model\WebhookTestResult;
use Oblodai\Contract\Request\PaymentTestingWebhookRequest;
use Oblodai\Contract\Request\TestWebhookPaymentRequest;
use Oblodai\Contract\Request\TestWebhookPayoutRequest;
use Oblodai\Contract\Request\TestWebhookWalletRequest;
use Oblodai\Contract\Request\WebhooksDeliveriesRequest;
use Oblodai\Core\Page;
use Oblodai\Core\RequestOptions;

/**
 * Webhook endpoint management and delivery inspection. Verification lives in
 * `Oblodai\Webhook\Verifier` and needs no client.
 */
final class Webhooks extends Resource
{
    /**
     * `POST /v1/webhooks` — register (or replace) the merchant's endpoint; returns the signing
     * secret once.
     */
    public function register(string $url, ?RequestOptions $options = null): WebhookEndpoint
    {
        return $this->call('POST /v1/webhooks', ['url' => $url], $options, WebhookEndpoint::fromArray(...));
    }

    /**
     * `POST /v1/webhooks/rotate-secret` — new secret; the old one keeps verifying until
     * `previous_secret_valid_until`. Payout key.
     */
    public function rotateSecret(?RequestOptions $options = null): WebhookSecretRotated
    {
        return $this->call(
            'POST /v1/webhooks/rotate-secret',
            null,
            $options,
            WebhookSecretRotated::fromArray(...)
        );
    }

    /**
     * `POST /v1/webhooks/deliveries` — delivery log, newest first.
     *
     * @param  array<string, mixed>|WebhooksDeliveriesRequest $params
     * @return Page<WebhookDelivery>
     */
    public function deliveries(
        array|WebhooksDeliveriesRequest $params = [],
        ?RequestOptions $options = null,
    ): Page {
        return $this->page('POST /v1/webhooks/deliveries', $params, WebhookDelivery::fromArray(...), $options);
    }

    /**
     * `POST /v1/test-webhook/{payment|payout|wallet}` — deliver a sample event of that kind to
     * `url_callback`, signed like a real one.
     *
     * @param array<string, mixed>|TestWebhookPaymentRequest|TestWebhookPayoutRequest|TestWebhookWalletRequest $params
     */
    public function test(
        WebhookKind|string $kind,
        array|TestWebhookPaymentRequest|TestWebhookPayoutRequest|TestWebhookWalletRequest $params,
        ?RequestOptions $options = null,
    ): WebhookTestResult {
        $name = $kind instanceof WebhookKind ? $kind->value : $kind;

        return $this->call(
            'POST /v1/test-webhook/' . $name,
            $params,
            $options,
            WebhookTestResult::fromArray(...)
        );
    }

    /**
     * `POST /v1/payment/testing-webhook` — the older rehearsal door (payment events only).
     *
     * @deprecated use `test(WebhookKind::Payment, …)`
     *
     * @param array<string, mixed>|PaymentTestingWebhookRequest $params
     */
    public function testLegacy(
        array|PaymentTestingWebhookRequest $params,
        ?RequestOptions $options = null,
    ): WebhookTestResult {
        return $this->call(
            'POST /v1/payment/testing-webhook',
            $params,
            $options,
            WebhookTestResult::fromArray(...)
        );
    }
}
