<?php

declare(strict_types=1);

namespace Oblodai;

use Oblodai\Core\Clock;
use Oblodai\Core\Hooks;
use Oblodai\Core\Retry;
use Oblodai\Core\Transport;
use Oblodai\Generated\Resource\Account;
use Oblodai\Generated\Resource\ApiAllowlist;
use Oblodai\Generated\Resource\Batches;
use Oblodai\Generated\Resource\Checkout;
use Oblodai\Generated\Resource\Documents;
use Oblodai\Generated\Resource\PaymentLinks;
use Oblodai\Generated\Resource\Payments;
use Oblodai\Generated\Resource\PayoutLinks;
use Oblodai\Generated\Resource\Payouts;
use Oblodai\Generated\Resource\Referrals;
use Oblodai\Generated\Resource\Refunds;
use Oblodai\Generated\Resource\Sandbox;
use Oblodai\Generated\Resource\Settings;
use Oblodai\Generated\Resource\Splits;
use Oblodai\Generated\Resource\Wallets;
use Oblodai\Generated\Resource\Webhooks;
use Oblodai\Http\CurlHttpClient;
use Oblodai\Http\HttpClient;
use Oblodai\Log\Logger;

/**
 * The Oblodai API client. One instance per API key; safe to reuse for the whole process.
 *
 * ```php
 * $oblodai = new Oblodai(publicId: 'oblodai_…', secret: 'oblodai_live_…');
 * $invoice = $oblodai->payments->create([
 *     'amount' => '25', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => 'o-1',
 * ]);
 * ```
 *
 * Credentials, base URL and admin token fall back to the environment (`OBLODAI_PUBLIC_ID`,
 * `OBLODAI_SECRET`, `OBLODAI_BASE_URL`, `OBLODAI_ADMIN_TOKEN`). Amounts are always decimal strings.
 * The resource namespaces and their methods are generated from the gateway's OpenAPI contract.
 */
final class Oblodai
{
    public const VERSION = '2.0.0';

    public readonly Payments $payments;
    public readonly PaymentLinks $paymentLinks;
    public readonly Refunds $refunds;
    public readonly Payouts $payouts;
    public readonly PayoutLinks $payoutLinks;
    public readonly Batches $batches;
    public readonly Splits $splits;
    public readonly Wallets $wallets;
    public readonly Account $account;
    public readonly Webhooks $webhooks;
    public readonly Settings $settings;
    public readonly ApiAllowlist $apiAllowlist;
    public readonly Referrals $referrals;
    public readonly Documents $documents;
    public readonly Checkout $checkout;
    public readonly Sandbox $sandbox;

    /** The transport, exposed for advanced use (custom routes, tests). */
    public readonly Transport $transport;

    public readonly Config $config;

    /**
     * @param string|null           $publicId   public id of the API key (`X-Public-Id`)
     * @param string|null           $secret     secret of the API key; only ever signs
     * @param string|null           $baseUrl    API origin; may carry a path prefix
     * @param HttpClient|null       $http       custom HTTP stack (see Psr18HttpClient)
     * @param int|float|null        $timeout    per-attempt timeout, seconds (default 30)
     * @param int|float|null        $deadline   budget per call including retries, seconds (default 90)
     * @param Retry|null            $retry      retry policy; `new Retry(maxRetries: 0)` disables retries
     * @param Logger|null           $logger     structured logger; `OBLODAI_LOG=debug` picks a console one
     * @param array<string, string> $headers    extra headers on every request
     * @param string|null           $adminToken admin token of a self-hosted gateway (onboarding routes)
     * @param bool|null             $allowInsecureBaseUrl permit plain http:// (local gateway, CI)
     * @param Clock|null            $clock      injectable clock, for tests
     * @param array<string, string>|null $env   environment override, for tests
     * @param Hooks|null            $hooks      request/response hooks (metrics, tracing)
     */
    public function __construct(
        ?string $publicId = null,
        ?string $secret = null,
        ?string $baseUrl = null,
        ?HttpClient $http = null,
        int|float|null $timeout = null,
        int|float|null $deadline = null,
        ?Retry $retry = null,
        ?Logger $logger = null,
        array $headers = [],
        ?string $adminToken = null,
        ?bool $allowInsecureBaseUrl = null,
        ?Clock $clock = null,
        ?array $env = null,
        ?Hooks $hooks = null,
    ) {
        $config = Config::resolve([
            'publicId' => $publicId,
            'secret' => $secret,
            'baseUrl' => $baseUrl,
            'adminToken' => $adminToken,
            'logger' => $logger,
            'allowInsecureBaseUrl' => $allowInsecureBaseUrl,
        ], $env);

        $this->attach($config, new Transport(
            baseUrl: $config->baseUrl,
            http: $http ?? new CurlHttpClient(),
            userAgent: self::userAgent(),
            credentials: $config->credentials,
            timeout: (float) ($timeout ?? 30),
            deadline: (float) ($deadline ?? 90),
            retry: $retry,
            clock: $clock,
            logger: $config->logger,
            headers: $headers,
            adminToken: $config->adminToken,
            hooks: $hooks,
        ));
    }

    /** `oblodai-php/2.0.0 (php 8.3.12)`. */
    public static function userAgent(): string
    {
        return sprintf('oblodai-php/%s (php %s)', self::VERSION, PHP_VERSION);
    }

    /**
     * A client with these settings overridden; the original is untouched, the HTTP client, clock
     * and hooks are shared.
     *
     * @param int|float|null        $timeout      per-attempt timeout, seconds
     * @param int|null              $maxRetries   replaces the retry policy's `maxRetries`
     * @param array<string, string> $extraHeaders merged over the client's headers
     */
    public function withOptions(int|float|null $timeout = null, ?int $maxRetries = null, array $extraHeaders = []): self
    {
        $copy = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $copy->attach($this->config, $this->transport->derive($timeout, $maxRetries, $extraHeaders));

        return $copy;
    }

    private function attach(Config $config, Transport $transport): void
    {
        $this->config = $config;
        $this->transport = $transport;
        $this->payments = new Payments($transport);
        $this->paymentLinks = new PaymentLinks($transport);
        $this->refunds = new Refunds($transport);
        $this->payouts = new Payouts($transport);
        $this->payoutLinks = new PayoutLinks($transport);
        $this->batches = new Batches($transport);
        $this->splits = new Splits($transport);
        $this->wallets = new Wallets($transport);
        $this->account = new Account($transport);
        $this->webhooks = new Webhooks($transport);
        $this->settings = new Settings($transport);
        $this->apiAllowlist = new ApiAllowlist($transport);
        $this->referrals = new Referrals($transport);
        $this->documents = new Documents($transport);
        $this->checkout = new Checkout($transport);
        $this->sandbox = new Sandbox($transport);
    }
}
