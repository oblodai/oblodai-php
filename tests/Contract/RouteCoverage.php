<?php

declare(strict_types=1);

namespace Oblodai\Tests\Contract;

use Closure;
use Oblodai\Contract\Enum\WebhookKind;
use Oblodai\Oblodai;

/**
 * The SDK's coverage ledger — the PHP mirror of sdk-node's `test/contract/routes.test.ts` COVERAGE
 * map. One closure per route key; `RoutesTest` calls each once against a scripted FakeHttpClient and
 * checks the single request it produced against the route's spec (method, path, auth, idempotency).
 *
 * A route the core declares that has no entry here fails loudly in `RoutesTest` (undefined array
 * key) rather than being silently skipped — same guarantee as the TypeScript original.
 */
final class RouteCoverage
{
    /** @return array<string, Closure(Oblodai): mixed> */
    public static function calls(): array
    {
        return [
            'GET /v1/claim/{token}' => static fn (Oblodai $ob): mixed => $ob->payoutLinks->claimPreview('tok'),
            'GET /v1/currencies' => static fn (Oblodai $ob): mixed => $ob->catalog->currencies(),
            'GET /v1/documents/balance' => static fn (Oblodai $ob): mixed => $ob->documents->balanceCertificate(),
            'GET /v1/documents/batch' => static fn (Oblodai $ob): mixed => $ob->documents->batchReport('b1'),
            'GET /v1/documents/fees' => static fn (Oblodai $ob): mixed => $ob->documents->feeSchedule(),
            'GET /v1/documents/jobs/file' => static fn (Oblodai $ob): mixed => $ob->documents->jobFile('j1'),
            'GET /v1/documents/ledger' => static fn (Oblodai $ob): mixed => $ob->documents->ledger(),
            'GET /v1/documents/link' => static fn (Oblodai $ob): mixed => $ob->documents->linkReport('l1'),
            'GET /v1/documents/referrals' => static fn (Oblodai $ob): mixed => $ob->documents->referralsReport(),
            'GET /v1/documents/split' => static fn (Oblodai $ob): mixed => $ob->documents->splitReport('i1'),
            'GET /v1/documents/statement' => static fn (Oblodai $ob): mixed => $ob->documents->statement([
                'from' => '2026-01-01', 'to' => '2026-02-01',
            ]),
            'GET /v1/documents/wallet/statement' => static fn (Oblodai $ob): mixed => $ob->documents->walletStatement('w1'),
            'GET /v1/documents/{kind}/{id}' => static fn (Oblodai $ob): mixed => $ob->documents->download(
                'invoice',
                'i1',
                ['exp' => 1, 'sig' => 's']
            ),
            'GET /v1/link/{id}' => static fn (Oblodai $ob): mixed => $ob->paymentLinks->publicView('l1'),
            'GET /v1/pay/{id}' => static fn (Oblodai $ob): mixed => $ob->payments->publicView('i1'),
            'GET /v1/pay/{id}/qr' => static fn (Oblodai $ob): mixed => $ob->payments->publicQr('i1'),
            'GET /v1/sandbox/webhooks' => static fn (Oblodai $ob): mixed => $ob->sandbox->webhooks()->items(),
            'POST /v1/api-allowlist/add' => static fn (Oblodai $ob): mixed => $ob->settings->addApiAllowlist('10.0.0.0/8'),
            'POST /v1/api-allowlist/enable' => static fn (Oblodai $ob): mixed => $ob->settings->enableApiAllowlist(true),
            'POST /v1/api-allowlist/list' => static fn (Oblodai $ob): mixed => $ob->settings->listApiAllowlist(),
            'POST /v1/api-allowlist/remove' => static fn (Oblodai $ob): mixed => $ob->settings->removeApiAllowlist('10.0.0.0/8'),
            'POST /v1/auto-withdraw/delete' => static fn (Oblodai $ob): mixed => $ob->settings->deleteAutoWithdraw('USDT'),
            'POST /v1/auto-withdraw/list' => static fn (Oblodai $ob): mixed => $ob->settings->listAutoWithdraw(),
            'POST /v1/auto-withdraw/set' => static fn (Oblodai $ob): mixed => $ob->settings->setAutoWithdraw([
                'currency' => 'USDT', 'network' => 'tron', 'address' => 'T',
            ]),
            'POST /v1/balance' => static fn (Oblodai $ob): mixed => $ob->account->balance(),
            'POST /v1/batch/info' => static fn (Oblodai $ob): mixed => $ob->batches->info(['batch_id' => 'b1']),
            'POST /v1/claim/{token}' => static fn (Oblodai $ob): mixed => $ob->payoutLinks->claim('tok', ['address' => 'T']),
            'POST /v1/exchange-rate/list' => static fn (Oblodai $ob): mixed => $ob->catalog->exchangeRates()->items(),
            'POST /v1/link/{id}/checkout' => static fn (Oblodai $ob): mixed => $ob->paymentLinks->checkout('l1'),
            'POST /v1/pay/{id}/select' => static fn (Oblodai $ob): mixed => $ob->payments->select('i1', [
                'currency' => 'USDT', 'network' => 'tron',
            ]),
            'POST /v1/payment' => static fn (Oblodai $ob): mixed => $ob->payments->create(['amount' => '1', 'currency' => 'USDT']),
            'POST /v1/payment/accepted/list' => static fn (Oblodai $ob): mixed => $ob->settings->listAccepted()->items(),
            'POST /v1/payment/accepted/set' => static fn (Oblodai $ob): mixed => $ob->settings->setAccepted(['accepted' => []]),
            'POST /v1/payment/accuracy/get' => static fn (Oblodai $ob): mixed => $ob->settings->getAccuracy(),
            'POST /v1/payment/accuracy/set' => static fn (Oblodai $ob): mixed => $ob->settings->setAccuracy(['enabled' => true]),
            'POST /v1/payment/autorefund/get' => static fn (Oblodai $ob): mixed => $ob->settings->getAutoRefund(),
            'POST /v1/payment/autorefund/set' => static fn (Oblodai $ob): mixed => $ob->settings->setAutoRefund([
                'overpay' => true, 'underpay' => false,
            ]),
            'POST /v1/payment/batch' => static fn (Oblodai $ob): mixed => $ob->payments->batch(['payments' => []]),
            'POST /v1/payment/cancel' => static fn (Oblodai $ob): mixed => $ob->payments->cancel(['uuid' => 'i1']),
            'POST /v1/payment/discount/list' => static fn (Oblodai $ob): mixed => $ob->settings->listDiscounts()->items(),
            'POST /v1/payment/discount/set' => static fn (Oblodai $ob): mixed => $ob->settings->setDiscount(['discount_percent' => 1]),
            'POST /v1/payment/fee-config/get' => static fn (Oblodai $ob): mixed => $ob->settings->getPaymentFeeConfig(),
            'POST /v1/payment/fee-config/set' => static fn (Oblodai $ob): mixed => $ob->settings->setPaymentFeeConfig([
                'payer_pays_percent' => 50,
            ]),
            'POST /v1/payment/history' => static fn (Oblodai $ob): mixed => $ob->payments->history()->items(),
            'POST /v1/payment/info' => static fn (Oblodai $ob): mixed => $ob->payments->info(['uuid' => 'i1']),
            'POST /v1/payment/link' => static fn (Oblodai $ob): mixed => $ob->paymentLinks->create([
                'amount_mode' => 'open', 'currency' => 'USDT',
            ]),
            'POST /v1/payment/link/info' => static fn (Oblodai $ob): mixed => $ob->paymentLinks->info('l1'),
            'POST /v1/payment/link/list' => static fn (Oblodai $ob): mixed => $ob->paymentLinks->list()->items(),
            'POST /v1/payment/link/toggle' => static fn (Oblodai $ob): mixed => $ob->paymentLinks->toggle('l1', false),
            'POST /v1/payment/qr' => static fn (Oblodai $ob): mixed => $ob->payments->qr(['uuid' => 'i1']),
            'POST /v1/payment/refund' => static fn (Oblodai $ob): mixed => $ob->refunds->create(['uuid' => 'i1']),
            'POST /v1/payment/resend' => static fn (Oblodai $ob): mixed => $ob->payments->resend(['uuid' => 'i1']),
            'POST /v1/payment/resolve' => static fn (Oblodai $ob): mixed => $ob->refunds->resolve([
                'action' => 'accept', 'uuid' => 'i1',
            ]),
            'POST /v1/payment/send-email' => static fn (Oblodai $ob): mixed => $ob->payments->sendEmail(['uuid' => 'i1']),
            'POST /v1/payment/services' => static fn (Oblodai $ob): mixed => $ob->payments->services()->items(),
            'POST /v1/payment/testing-webhook' => static fn (Oblodai $ob): mixed => $ob->webhooks->testLegacy([
                'url' => 'https://x',
            ]),
            'POST /v1/payout' => static fn (Oblodai $ob): mixed => $ob->payouts->create([
                'amount' => '1', 'currency' => 'USDT', 'address' => 'T', 'order_id' => 'o',
            ]),
            'POST /v1/payout/approve' => static fn (Oblodai $ob): mixed => $ob->payouts->approve('p1'),
            'POST /v1/payout/batch' => static fn (Oblodai $ob): mixed => $ob->payouts->batch(['payouts' => []]),
            'POST /v1/payout/calculate' => static fn (Oblodai $ob): mixed => $ob->payouts->calculate([
                'amount' => '1', 'currency' => 'USDT',
            ]),
            'POST /v1/payout/cancel' => static fn (Oblodai $ob): mixed => $ob->payouts->cancel('p1'),
            'POST /v1/payout/fee-config/get' => static fn (Oblodai $ob): mixed => $ob->payouts->getFeeConfig(),
            'POST /v1/payout/fee-config/set' => static fn (Oblodai $ob): mixed => $ob->payouts->setFeeConfig([
                'fee_on_recipient' => true,
            ]),
            'POST /v1/payout/history' => static fn (Oblodai $ob): mixed => $ob->payouts->history()->items(),
            'POST /v1/payout/info' => static fn (Oblodai $ob): mixed => $ob->payouts->info(['uuid' => 'p1']),
            'POST /v1/payout/link' => static fn (Oblodai $ob): mixed => $ob->payoutLinks->create([
                'amount' => '1', 'currency' => 'USDT', 'network' => 'tron',
            ]),
            'POST /v1/payout/link/batch' => static fn (Oblodai $ob): mixed => $ob->payoutLinks->batch(['items' => []]),
            'POST /v1/payout/link/cancel' => static fn (Oblodai $ob): mixed => $ob->payoutLinks->cancel('l1'),
            'POST /v1/payout/link/cheque' => static fn (Oblodai $ob): mixed => $ob->payoutLinks->cheque(['claim_token' => 't']),
            'POST /v1/payout/link/info' => static fn (Oblodai $ob): mixed => $ob->payoutLinks->info('l1'),
            'POST /v1/payout/link/list' => static fn (Oblodai $ob): mixed => $ob->payoutLinks->list()->items(),
            'POST /v1/payout/mass' => static fn (Oblodai $ob): mixed => $ob->payouts->mass(['payouts' => []]),
            'POST /v1/payout/refund-fee-config/get' => static fn (Oblodai $ob): mixed => $ob->payouts->getRefundFeeConfig(),
            'POST /v1/payout/refund-fee-config/set' => static fn (Oblodai $ob): mixed => $ob->payouts->setRefundFeeConfig([
                'fee_on_customer' => true,
            ]),
            'POST /v1/payout/services' => static fn (Oblodai $ob): mixed => $ob->payouts->services()->items(),
            'POST /v1/payout/validate' => static fn (Oblodai $ob): mixed => $ob->payouts->validate([
                'amount' => '1', 'currency' => 'USDT', 'address' => 'T',
            ]),
            'POST /v1/referral/info' => static fn (Oblodai $ob): mixed => $ob->account->referral(),
            'POST /v1/refund/batch' => static fn (Oblodai $ob): mixed => $ob->refunds->batch(['refunds' => []]),
            'POST /v1/sandbox/deposit' => static fn (Oblodai $ob): mixed => $ob->sandbox->deposit(['invoice_id' => 'i1']),
            'POST /v1/sandbox/faucet' => static fn (Oblodai $ob): mixed => $ob->sandbox->faucet(['asset' => 'USDT', 'amount' => '1']),
            'POST /v1/sandbox/reset' => static fn (Oblodai $ob): mixed => $ob->sandbox->reset(),
            'POST /v1/sandbox/webhooks/replay' => static fn (Oblodai $ob): mixed => $ob->sandbox->replay('d1'),
            'POST /v1/split/config/get' => static fn (Oblodai $ob): mixed => $ob->splits->getConfig(),
            'POST /v1/split/config/set' => static fn (Oblodai $ob): mixed => $ob->splits->setConfig(['refund_hold_seconds' => 60]),
            'POST /v1/split/recipient/optin' => static fn (Oblodai $ob): mixed => $ob->splits->setOptIn(true),
            'POST /v1/split/recipient/optin/get' => static fn (Oblodai $ob): mixed => $ob->splits->getOptIn(),
            'POST /v1/split/rule' => static fn (Oblodai $ob): mixed => $ob->splits->createRule(['percent' => '10']),
            'POST /v1/split/rule/delete' => static fn (Oblodai $ob): mixed => $ob->splits->deleteRule('r1'),
            'POST /v1/split/rule/list' => static fn (Oblodai $ob): mixed => $ob->splits->listRules()->items(),
            'POST /v1/test-webhook/payment' => static fn (Oblodai $ob): mixed => $ob->webhooks->test(
                WebhookKind::Payment,
                ['url_callback' => 'https://x']
            ),
            'POST /v1/test-webhook/payout' => static fn (Oblodai $ob): mixed => $ob->webhooks->test(
                WebhookKind::Payout,
                ['url_callback' => 'https://x']
            ),
            'POST /v1/test-webhook/wallet' => static fn (Oblodai $ob): mixed => $ob->webhooks->test(
                WebhookKind::Wallet,
                ['url_callback' => 'https://x']
            ),
            'POST /v1/transfer/batch' => static fn (Oblodai $ob): mixed => $ob->transfers->batch([]),
            'POST /v1/transfer/to-personal' => static fn (Oblodai $ob): mixed => $ob->transfers->toPersonal([
                'amount' => '1', 'currency' => 'USDT',
            ]),
            'POST /v1/transfer/to-user' => static fn (Oblodai $ob): mixed => $ob->transfers->toUser(['to_user_id' => 'u']),
            'POST /v1/vrcs' => static fn (Oblodai $ob): mixed => $ob->account->vrcs(),
            'POST /v1/wallet' => static fn (Oblodai $ob): mixed => $ob->wallets->create(['currency' => 'USDT', 'network' => 'tron']),
            'POST /v1/wallet/block' => static fn (Oblodai $ob): mixed => $ob->wallets->block(['address' => 'T']),
            'POST /v1/wallet/blocked-address-refund' => static fn (Oblodai $ob): mixed => $ob->wallets->refundBlockedDeposit([
                'uuid' => 'w1', 'address' => 'T',
            ]),
            'POST /v1/wallet/qr' => static fn (Oblodai $ob): mixed => $ob->wallets->qr('T'),
            'POST /v1/webhooks' => static fn (Oblodai $ob): mixed => $ob->webhooks->register('https://x'),
            'POST /v1/webhooks/deliveries' => static fn (Oblodai $ob): mixed => $ob->webhooks->deliveries()->items(),
            'POST /v1/webhooks/rotate-secret' => static fn (Oblodai $ob): mixed => $ob->webhooks->rotateSecret(),
            'POST /v1/documents/jobs' => static fn (Oblodai $ob): mixed => $ob->documents->createJob(['kind' => 'statement']),
            'POST /v1/documents/jobs/info' => static fn (Oblodai $ob): mixed => $ob->documents->jobInfo('j1'),
            'POST /v1/merchants' => static fn (Oblodai $ob): mixed => $ob->merchants->create(['email' => 'a@b.c', 'name' => 'A']),
            'POST /v1/merchants/{id}/sandbox' => static fn (Oblodai $ob): mixed => $ob->merchants->createSandbox('m1'),
        ];
    }
}
