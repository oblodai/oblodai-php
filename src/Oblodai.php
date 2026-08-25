<?php

declare(strict_types=1);

namespace Oblodai;

use Oblodai\Contract\Version;
use Oblodai\Core\Clock;
use Oblodai\Core\Retry;
use Oblodai\Core\Transport;
use Oblodai\Http\CurlHttpClient;
use Oblodai\Http\HttpClient;
use Oblodai\Log\Logger;
use Oblodai\Resource\Account;
use Oblodai\Resource\Batches;
use Oblodai\Resource\Catalog;
use Oblodai\Resource\Documents;
use Oblodai\Resource\Merchants;
use Oblodai\Resource\PaymentLinks;
use Oblodai\Resource\Payments;
use Oblodai\Resource\PayoutLinks;
use Oblodai\Resource\Payouts;
use Oblodai\Resource\Refunds;
use Oblodai\Resource\Sandbox;
use Oblodai\Resource\Settings;
use Oblodai\Resource\Splits;
use Oblodai\Resource\Transfers;
use Oblodai\Resource\Wallets;
use Oblodai\Resource\Webhooks;

/**
 * The Oblodai API client. One instance per key pair; safe to reuse for the whole process.
 *
 * ```php
 * $oblodai = new Oblodai(publicId: 'pk_live_…', secret: '…');
 * $invoice = $oblodai->payments->create([
 *     'amount' => '25', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => 'o-1',
 * ]);
 * ```
 *
 * Credentials fall back to the environment (`OBLODAI_PUBLIC_ID`, `OBLODAI_SECRET`, and the
 * `OBLODAI_PAYOUT_*` pair for the payout key). Amounts are always decimal strings.
 */
final class Oblodai
{
    public const VERSION = '1.3.0';

    public readonly Payments $payments;
    public readonly Refunds $refunds;
    public readonly Payouts $payouts;
    public readonly PayoutLinks $payoutLinks;
    public readonly PaymentLinks $paymentLinks;
    public readonly Batches $batches;
    public readonly Transfers $transfers;
    public readonly Wallets $wallets;
    public readonly Webhooks $webhooks;
    public readonly Documents $documents;
    public readonly Splits $splits;
    public readonly Settings $settings;
    public readonly Account $account;
    public readonly Catalog $catalog;
    public readonly Sandbox $sandbox;
    public readonly Merchants $merchants;

    /** The transport, exposed for advanced use (custom routes, tests). */
    public readonly Transport $transport;

    public readonly Config $config;

    /**
     * @param string|null          $publicId       public id of the API key (`X-Public-Id`)
     * @param string|null          $secret         secret of the API key; only ever signs
     * @param string|null          $payoutPublicId optional dedicated payout key
     * @param string|null          $payoutSecret   secret of the payout key
     * @param string|null          $baseUrl        API origin; may carry a path prefix
     * @param HttpClient|null      $http           custom HTTP stack (see Psr18HttpClient)
     * @param int|null             $timeoutMs      per-attempt timeout, default 30000
     * @param int|null             $deadlineMs     overall budget per call including retries, default 90000
     * @param Retry|null           $retry          retry policy; `new Retry(maxRetries: 0)` disables retries
     * @param Logger|null          $logger         structured logger; `OBLODAI_LOG=debug` picks a console one
     * @param array<string,string> $headers        extra headers on every request
     * @param string|null          $adminToken     admin token of a self-hosted gateway (onboarding routes)
     * @param bool|null            $allowInsecureBaseUrl permit plain http:// (local core, CI)
     * @param Clock|null           $clock          injectable clock, for tests
     * @param array<string,string>|null $env       environment override, for tests
     */
    public function __construct(
        ?string $publicId = null,
        ?string $secret = null,
        ?string $payoutPublicId = null,
        ?string $payoutSecret = null,
        ?string $baseUrl = null,
        ?HttpClient $http = null,
        ?int $timeoutMs = null,
        ?int $deadlineMs = null,
        ?Retry $retry = null,
        ?Logger $logger = null,
        array $headers = [],
        ?string $adminToken = null,
        ?bool $allowInsecureBaseUrl = null,
        ?Clock $clock = null,
        ?array $env = null,
    ) {
        $this->config = Config::resolve([
            'publicId' => $publicId,
            'secret' => $secret,
            'payoutPublicId' => $payoutPublicId,
            'payoutSecret' => $payoutSecret,
            'baseUrl' => $baseUrl,
            'adminToken' => $adminToken,
            'logger' => $logger,
            'allowInsecureBaseUrl' => $allowInsecureBaseUrl,
        ], $env);

        $this->transport = new Transport(
            baseUrl: $this->config->baseUrl,
            http: $http ?? new CurlHttpClient(),
            userAgent: sprintf(
                'oblodai-php/%s (contract %s; php %s)',
                self::VERSION,
                substr(Version::CONTRACT_HASH, 0, 12),
                PHP_VERSION
            ),
            credentials: $this->config->credentials,
            payoutCredentials: $this->config->payoutCredentials,
            timeoutMs: $timeoutMs ?? 30000,
            deadlineMs: $deadlineMs ?? 90000,
            retry: $retry,
            clock: $clock,
            logger: $this->config->logger,
            headers: $headers,
            adminToken: $this->config->adminToken,
        );

        $this->payments = new Payments($this->transport);
        $this->refunds = new Refunds($this->transport);
        $this->payouts = new Payouts($this->transport);
        $this->payoutLinks = new PayoutLinks($this->transport);
        $this->paymentLinks = new PaymentLinks($this->transport);
        $this->batches = new Batches($this->transport);
        $this->transfers = new Transfers($this->transport);
        $this->wallets = new Wallets($this->transport);
        $this->webhooks = new Webhooks($this->transport);
        $this->documents = new Documents($this->transport);
        $this->splits = new Splits($this->transport);
        $this->settings = new Settings($this->transport);
        $this->account = new Account($this->transport);
        $this->catalog = new Catalog($this->transport);
        $this->sandbox = new Sandbox($this->transport);
        $this->merchants = new Merchants($this->transport);
    }
}
