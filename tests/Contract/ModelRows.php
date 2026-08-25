<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Oblodai\Contract\Model as M;

/**
 * Wire models versus the golden bodies the core recorded — the PHP mirror of sdk-node's
 * `test/contract/models.test.ts` ROWS table. Each row names a route, how to reach the object inside
 * its recorded `result`, and the model's key tuple (from the model's own `KEYS` constant — never
 * re-typed by hand). `ModelsTest` asserts the picked object's key set equals `keys` modulo
 * `optional`, in both directions, and that the model's `fromArray()` decodes it without throwing.
 */
final class ModelRows
{
    /**
     * Navigate nested offsets of a decoded-JSON value without PHPStan losing track through the
     * `mixed` a fixture's `result` really is — each segment is checked before it is followed.
     */
    private static function at(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return list<array{
     *     0: string,
     *     1: callable(array<string, mixed>): mixed,
     *     2: list<string>,
     *     3: list<string>
     * }>
     */
    public static function rows(): array
    {
        return [
            ['POST /v1/payment', static fn (array $r): mixed => $r, M\Payment::KEYS, []],
            ['POST /v1/payment/info', static fn (array $r): mixed => $r, M\Payment::KEYS, ['refunds', 'refund_status']],
            ['POST /v1/payment/cancel', static fn (array $r): mixed => $r, M\Payment::KEYS, []],
            ['POST /v1/payment/history', static fn (array $r): mixed => self::at($r, 'items', 0), M\Payment::KEYS, []],
            ['GET /v1/pay/{id}', static fn (array $r): mixed => $r, M\PublicPayment::KEYS, []],
            ['POST /v1/pay/{id}/select', static fn (array $r): mixed => $r, M\PublicPayment::KEYS, []],
            ['POST /v1/link/{id}/checkout', static fn (array $r): mixed => $r, M\PublicPayment::KEYS, []],
            ['POST /v1/payment/qr', static fn (array $r): mixed => $r, M\QrCode::KEYS, []],
            ['GET /v1/pay/{id}/qr', static fn (array $r): mixed => $r, M\QrCode::KEYS, []],
            ['POST /v1/payment/services', static fn (array $r): mixed => self::at($r, 'items', 0), M\ServiceMethod::KEYS, []],
            ['POST /v1/payout/services', static fn (array $r): mixed => self::at($r, 'items', 0), M\ServiceMethod::KEYS, []],
            ['POST /v1/payment/batch', static fn (array $r): mixed => $r, M\BatchSubmitted::KEYS, []],
            ['POST /v1/payout/batch', static fn (array $r): mixed => $r, M\BatchSubmitted::KEYS, []],
            ['POST /v1/refund/batch', static fn (array $r): mixed => $r, M\BatchSubmitted::KEYS, []],
            ['POST /v1/transfer/batch', static fn (array $r): mixed => $r, M\BatchSubmitted::KEYS, []],
            ['POST /v1/batch/info', static fn (array $r): mixed => $r, M\BatchInfo::KEYS, []],
            ['POST /v1/payout', static fn (array $r): mixed => $r, M\Payout::KEYS, []],
            ['POST /v1/payout/info', static fn (array $r): mixed => $r, M\Payout::KEYS, ['error', 'error_code']],
            ['POST /v1/payout/cancel', static fn (array $r): mixed => $r, M\Payout::KEYS, []],
            ['POST /v1/payout/history', static fn (array $r): mixed => self::at($r, 'items', 0), M\Payout::KEYS, []],
            ['POST /v1/payout/mass', static fn (array $r): mixed => self::at($r, 'items', 0, 'result'), M\Payout::KEYS, []],
            ['POST /v1/payment/refund', static fn (array $r): mixed => $r, M\Payout::KEYS, []],
            ['POST /v1/payout/calculate', static fn (array $r): mixed => $r, M\PayoutCalculation::KEYS, []],
            ['POST /v1/payout/validate', static fn (array $r): mixed => $r, M\PayoutValidation::KEYS, []],
            ['POST /v1/payout/link', static fn (array $r): mixed => $r, M\PayoutLink::KEYS, ['claim_token', 'claim_url']],
            ['POST /v1/payout/link/info', static fn (array $r): mixed => $r, M\PayoutLink::KEYS, []],
            ['POST /v1/payout/link/list', static fn (array $r): mixed => self::at($r, 'items', 0), M\PayoutLink::KEYS, []],
            ['POST /v1/payout/link/cancel', static fn (array $r): mixed => $r, M\PayoutLink::KEYS, []],
            [
                'POST /v1/payout/link/batch',
                static fn (array $r): mixed => self::at($r, 'items', 0, 'result'),
                M\PayoutLink::KEYS,
                ['claim_token', 'claim_url', 'batch_id'],
            ],
            ['GET /v1/claim/{token}', static fn (array $r): mixed => $r, M\ClaimPreview::KEYS, []],
            ['POST /v1/claim/{token}', static fn (array $r): mixed => $r, M\ClaimResult::KEYS, []],
            ['POST /v1/payment/link', static fn (array $r): mixed => $r, M\PaymentLinkCreated::KEYS, []],
            ['POST /v1/payment/link/info', static fn (array $r): mixed => $r, M\PaymentLink::KEYS, ['payments']],
            ['POST /v1/payment/link/list', static fn (array $r): mixed => self::at($r, 'items', 0), M\PaymentLink::KEYS, []],
            ['GET /v1/link/{id}', static fn (array $r): mixed => $r, M\PublicPaymentLink::KEYS, []],
            ['POST /v1/balance', static fn (array $r): mixed => $r, M\Balance::KEYS, []],
            ['POST /v1/referral/info', static fn (array $r): mixed => $r, M\ReferralInfo::KEYS, []],
            ['POST /v1/auto-withdraw/list', static fn (array $r): mixed => self::at($r, 'items', 0), M\AutoWithdrawRule::KEYS, []],
            ['POST /v1/api-allowlist/list', static fn (array $r): mixed => $r, M\ApiAllowlist::KEYS, []],
            ['POST /v1/payment/discount/list', static fn (array $r): mixed => self::at($r, 'items', 0), M\DiscountRule::KEYS, []],
            ['POST /v1/split/rule/list', static fn (array $r): mixed => self::at($r, 'items', 0), M\SplitRule::KEYS, []],
            ['GET /v1/currencies', static fn (array $r): mixed => $r, M\Currencies::KEYS, []],
            [
                'GET /v1/currencies',
                static fn (array $r): mixed => self::at($r, 'currencies', 0, 'networks', 0),
                M\CurrencyNetwork::KEYS,
                ['contract'],
            ],
            ['POST /v1/exchange-rate/list', static fn (array $r): mixed => self::at($r, 'items', 0), M\ExchangeRate::KEYS, []],
            ['POST /v1/webhooks', static fn (array $r): mixed => $r, M\WebhookEndpoint::KEYS, []],
            ['POST /v1/webhooks/rotate-secret', static fn (array $r): mixed => $r, M\WebhookSecretRotated::KEYS, []],
            ['POST /v1/webhooks/deliveries', static fn (array $r): mixed => self::at($r, 'items', 0), M\WebhookDelivery::KEYS, []],
            [
                'GET /v1/sandbox/webhooks',
                static fn (array $r): mixed => self::at($r, 'items', 0),
                M\WebhookDelivery::KEYS,
                ['payload', 'sequence'],
            ],
            ['POST /v1/payment/resolve', static fn (array $r): mixed => $r, array_merge(M\Payout::KEYS, ['resolution']), []],
            ['POST /v1/payment/send-email', static fn (array $r): mixed => $r, M\EmailSent::KEYS, []],
            ['POST /v1/payment/resend', static fn (array $r): mixed => $r, M\OkResult::KEYS, []],
            ['POST /v1/payment/accepted/set', static fn (array $r): mixed => $r, M\OkResult::KEYS, []],
            ['POST /v1/split/rule/delete', static fn (array $r): mixed => $r, M\OkResult::KEYS, []],
            [
                'POST /v1/payment/accepted/list',
                static fn (array $r): mixed => self::at($r, 'items', 0),
                M\AcceptedMethod::KEYS,
                ['reason'],
            ],
            ['POST /v1/payment/accuracy/get', static fn (array $r): mixed => $r, M\AccuracyConfig::KEYS, []],
            ['POST /v1/payment/accuracy/set', static fn (array $r): mixed => $r, M\AccuracyConfig::KEYS, []],
            ['POST /v1/payment/autorefund/get', static fn (array $r): mixed => $r, M\AutoRefundConfig::KEYS, []],
            [
                'POST /v1/payment/autorefund/set',
                static fn (array $r): mixed => $r,
                M\AutoRefundConfig::KEYS,
                ['configured'],
            ],
            ['POST /v1/payment/discount/set', static fn (array $r): mixed => $r, M\DiscountRule::KEYS, []],
            ['POST /v1/payment/fee-config/get', static fn (array $r): mixed => $r, M\PaymentFeeConfig::KEYS, []],
            [
                'POST /v1/payment/fee-config/set',
                static fn (array $r): mixed => $r,
                M\PaymentFeeConfig::KEYS,
                ['enabled'],
            ],
            ['POST /v1/payout/fee-config/get', static fn (array $r): mixed => $r, M\PayoutFeeConfig::KEYS, []],
            [
                'POST /v1/payout/fee-config/set',
                static fn (array $r): mixed => $r,
                M\PayoutFeeConfig::KEYS,
                ['configured'],
            ],
            ['POST /v1/payout/refund-fee-config/get', static fn (array $r): mixed => $r, M\RefundFeeConfig::KEYS, []],
            [
                'POST /v1/payout/refund-fee-config/set',
                static fn (array $r): mixed => $r,
                M\RefundFeeConfig::KEYS,
                ['configured'],
            ],
            ['POST /v1/payment/link/toggle', static fn (array $r): mixed => $r, M\PaymentLinkToggled::KEYS, []],
            ['POST /v1/split/rule', static fn (array $r): mixed => $r, M\SplitRule::CREATED_KEYS, []],
            ['POST /v1/split/config/get', static fn (array $r): mixed => $r, M\SplitConfig::KEYS, []],
            ['POST /v1/split/config/set', static fn (array $r): mixed => $r, M\SplitConfig::KEYS, []],
            ['POST /v1/split/recipient/optin', static fn (array $r): mixed => $r, M\SplitOptIn::KEYS, []],
            ['POST /v1/split/recipient/optin/get', static fn (array $r): mixed => $r, M\SplitOptIn::KEYS, []],
            ['POST /v1/vrcs', static fn (array $r): mixed => $r, M\VrcsStatus::KEYS, []],
            ['POST /v1/auto-withdraw/set', static fn (array $r): mixed => self::at($r, 'items', 0), M\AutoWithdrawRule::KEYS, []],
            ['POST /v1/auto-withdraw/delete', static fn (array $r): mixed => $r, ['items'], []],
            ['POST /v1/api-allowlist/add', static fn (array $r): mixed => $r, M\ApiAllowlist::KEYS, []],
            ['POST /v1/api-allowlist/remove', static fn (array $r): mixed => $r, M\ApiAllowlist::KEYS, []],
            ['POST /v1/api-allowlist/enable', static fn (array $r): mixed => $r, M\ApiAllowlist::KEYS, []],
            [
                'POST /v1/wallet',
                static fn (array $r): mixed => $r,
                M\Wallet::KEYS,
                ['destination_tag', 'memo', 'address_xaddress', 'address_muxed'],
            ],
            ['POST /v1/wallet/block', static fn (array $r): mixed => $r, M\WalletBlocked::KEYS, []],
            ['POST /v1/wallet/qr', static fn (array $r): mixed => $r, ['image'], []],
            [
                'POST /v1/wallet/blocked-address-refund',
                static fn (array $r): mixed => $r,
                array_merge(M\Payout::KEYS, ['wallet_uuid']),
                [],
            ],
            ['POST /v1/transfer/to-personal', static fn (array $r): mixed => $r, M\TransferToPersonal::KEYS, []],
            ['POST /v1/transfer/to-user', static fn (array $r): mixed => $r, M\TransferToUser::KEYS, []],
            [
                'POST /v1/documents/jobs',
                static fn (array $r): mixed => $r,
                M\DocumentJob::KEYS,
                ['ready_within', 'file', 'error'],
            ],
            [
                'POST /v1/documents/jobs/info',
                static fn (array $r): mixed => $r,
                M\DocumentJob::KEYS,
                ['ready_within', 'file', 'error'],
            ],
            [
                'POST /v1/documents/jobs/info',
                static fn (array $r): mixed => $r['file'],
                ['download_url', 'expires_at', 'rows', 'size_bytes'],
                [],
            ],
            ['POST /v1/test-webhook/payment', static fn (array $r): mixed => $r, M\WebhookTestResult::KEYS, []],
            ['POST /v1/test-webhook/payout', static fn (array $r): mixed => $r, M\WebhookTestResult::KEYS, []],
            ['POST /v1/test-webhook/wallet', static fn (array $r): mixed => $r, M\WebhookTestResult::KEYS, []],
            [
                'POST /v1/payment/testing-webhook',
                static fn (array $r): mixed => $r,
                array_merge(M\WebhookTestResult::KEYS, ['url', 'duration_ms']),
                [],
            ],
            ['POST /v1/sandbox/faucet', static fn (array $r): mixed => $r, M\FaucetResult::KEYS, []],
            ['POST /v1/sandbox/deposit', static fn (array $r): mixed => $r, M\SandboxDeposit::KEYS, []],
            ['POST /v1/sandbox/reset', static fn (array $r): mixed => $r, M\SandboxReset::KEYS, []],
            ['POST /v1/sandbox/webhooks/replay', static fn (array $r): mixed => $r, M\SandboxReplay::KEYS, []],
            ['POST /v1/merchants', static fn (array $r): mixed => $r, M\MerchantOnboarded::KEYS, []],
            [
                'POST /v1/merchants',
                static fn (array $r): mixed => $r['api_key'],
                ['public_id', 'secret', 'kind'],
                [],
            ],
            ['POST /v1/merchants/{id}/sandbox', static fn (array $r): mixed => $r, M\SandboxStore::KEYS, []],
        ];
    }

    /**
     * Routes the API guarantees to refuse for API keys — no success body exists to model.
     *
     * @return array<string, bool>
     */
    public static function notModelled(): array
    {
        return [
            // API-key payouts auto-approve; approve serves the cabinet's maker-checker flow.
            'POST /v1/payout/approve' => true,
        ];
    }
}
