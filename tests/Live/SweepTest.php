<?php

declare(strict_types=1);

namespace Oblodai\Tests\Live;

use Oblodai\Exception\NotFoundException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Generated\Model\PaymentView;
use Oblodai\Generated\Model\PayoutLinkCreated;
use Oblodai\Generated\Resource\Batches;
use Oblodai\Generated\Resource\Documents;
use Oblodai\Oblodai;

/**
 * Every namespace against a real gateway. The point is not the business outcome but that the
 * bodies the SDK sends are accepted (no 400 from our own shapes) and the bodies that come back
 * decode (no ContractException). Routes that need a subsystem the stand may lack (documents,
 * email, static wallets) are probed and tolerated when the gateway reports them disabled.
 *
 * @group live
 */
final class SweepTest extends LiveTestCase
{
    private static Oblodai $ob;
    private static Oblodai $pub;
    private static PaymentView $invoice;
    private static ?PayoutLinkCreated $link = null;
    private static bool $docsEnabled = true;

    public function testBootstrap(): void
    {
        self::$ob = self::onboardSandbox('sweep');
        self::$pub = self::anonymous();
        self::$ob->sandbox->faucet(['asset' => 'USDT', 'amount' => '1000']);
        // A per-invoice url_callback needs a registered endpoint (it signs with its secret).
        self::$ob->webhooks->register(['url' => self::hookUrl()]);
        self::$invoice = self::$ob->payments->create([
            'amount' => '25',
            'currency' => 'USDT',
            'network' => 'tron',
            'order_id' => self::uniqueId('sw'),
            'payer_email' => 'buyer@example.com',
            'url_callback' => self::hookUrl(),
        ]);
        self::assertNotSame('', self::$invoice->uuid);

        try {
            self::$ob->documents->getBalance();
        } catch (NotFoundException $err) {
            self::$docsEnabled = $err->errorCode !== 'document.disabled';
        } catch (OblodaiException) {
            self::$docsEnabled = false;
        }
    }

    /** @depends testBootstrap */
    public function testCatalogAndAccount(): void
    {
        self::assertNotEmpty(self::$pub->checkout->listCurrencies()->currencies);
        self::$pub->account->listExchangeRates(['currency_from' => 'BTC']);
        self::$ob->account->getBalance();
        self::$ob->referrals->getInfo();
        self::$ob->settings->configureVrcs();
        self::$ob->settings->configureVrcs(['enabled' => false]);
        $this->addToAssertionCount(1);
    }

    /** @depends testBootstrap */
    public function testPaymentsLookupsQrServicesPublicCheckoutBatch(): void
    {
        $ob = self::$ob;
        $invoice = self::$invoice;
        self::assertSame($invoice->uuid, $ob->payments->getInfo(['uuid' => $invoice->uuid])->uuid);
        // Sandbox invoices carry a synthetic `sandbox:` address, which the gateway deliberately
        // does not render into a QR — the fields come back empty. A real invoice returns a data URI.
        $ob->payments->getQr(['uuid' => $invoice->uuid]);
        self::assertNotEmpty($ob->payments->listServices(['limit' => 5])->items());
        self::$pub->checkout->get($invoice->uuid);
        self::$pub->checkout->getQr($invoice->uuid);

        $multi = $ob->payments->create(['amount' => '10', 'currency' => 'USDT', 'order_id' => self::uniqueId('sw-multi')]);
        self::$pub->checkout->selectMethod($multi->uuid, ['currency' => 'USDT', 'network' => 'tron']);

        $this->accept(static fn () => $ob->webhooks->resendPayment(['uuid' => $invoice->uuid]));
        $this->accept(static fn () => $ob->payments->sendEmail(['uuid' => $invoice->uuid]));

        $job = $ob->batches->asJob(static fn (Batches $b) => $b->createPayment([
            'on_error' => 'continue',
            'payments' => [
                ['amount' => '5', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => self::uniqueId('sw-b')],
            ],
        ]));
        self::assertSame($job->id, $ob->batches->getInfo(['batch_id' => $job->id])->batch_id);

        $toCancel = $ob->payments->create(['amount' => '5', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => self::uniqueId('sw-c')]);
        $ob->payments->cancel(['uuid' => $toCancel->uuid]);
        foreach ($ob->payments->listHistory(['limit' => 2]) as $payment) {
            self::assertNotSame('', $payment->uuid);

            break;
        }
    }

    /** @depends testBootstrap */
    public function testDepositPaidRefundResolveRefundBatch(): void
    {
        $ob = self::$ob;
        $invoice = self::$invoice;
        $ob->sandbox->simulateDeposit([
            'invoice_id' => $invoice->uuid,
            'amount' => '25',
            'confirmations' => 20,
            'txid' => self::uniqueId('sw-tx'),
        ]);
        $ob->payments->getInfo(['uuid' => $invoice->uuid]);
        $this->accept(static fn () => $ob->refunds->payment([
            'uuid' => $invoice->uuid, 'address' => self::ADDRESS, 'amount' => '5', 'reference' => self::uniqueId('sw-r'),
        ]));
        $this->accept(static fn () => $ob->payments->resolve(['uuid' => $invoice->uuid, 'action' => 'accept']));
        $this->accept(static fn () => $ob->batches->createRefund(['refunds' => [[
            'uuid' => $invoice->uuid, 'address' => self::ADDRESS, 'amount' => '5', 'reference' => self::uniqueId('sw-rb'),
        ]]]));
        $this->addToAssertionCount(1);
    }

    /** @depends testBootstrap */
    public function testPayoutsEveryRoute(): void
    {
        $ob = self::$ob;
        self::assertSame('USDT', $ob->payouts->calculate(['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])->currency);
        self::assertTrue($ob->payouts->validate(['amount' => '10', 'currency' => 'USDT', 'network' => 'tron', 'address' => self::ADDRESS])->valid);
        $payout = $ob->payouts->create([
            'amount' => '10', 'currency' => 'USDT', 'network' => 'tron', 'address' => self::ADDRESS, 'order_id' => self::uniqueId('sw-po'),
        ]);
        self::assertSame($payout->uuid, $ob->payouts->getInfo(['order_id' => (string) $payout->order_id])->uuid);
        $this->accept(static fn () => $ob->payouts->cancel(['uuid' => $payout->uuid]));
        $this->accept(static fn () => $ob->payouts->approve(['uuid' => $payout->uuid]));

        $mass = $ob->payouts->createMass(['payouts' => [[
            'amount' => '5', 'currency' => 'USDT', 'network' => 'tron', 'address' => self::ADDRESS, 'order_id' => self::uniqueId('sw-m'),
        ]]]);
        self::assertSame(0, $mass->items[0]->idx);

        $batch = $ob->batches->createPayout(['payouts' => [[
            'amount' => '5', 'currency' => 'USDT', 'network' => 'tron', 'address' => self::ADDRESS, 'order_id' => self::uniqueId('sw-pb'),
        ]]]);
        self::assertNotSame('', $batch->batch_id);
        self::assertNotEmpty($ob->payouts->listServices()->items());
        $ob->settings->setPayoutFeeConfig(['fee_on_recipient' => true]);
        $ob->settings->getPayoutFeeConfig();
        $ob->settings->setRefundFeeConfig(['fee_on_customer' => true]);
        $ob->settings->getRefundFeeConfig();
        $ob->payouts->listHistory(['kind' => 'refund', 'limit' => 5])->items();
    }

    /** @depends testBootstrap */
    public function testPayoutLinks(): void
    {
        $ob = self::$ob;
        $link = $ob->payoutLinks->create([
            'amount' => '5',
            'currency' => 'USDT',
            'network' => 'tron',
            'reference' => self::uniqueId('sw-pl'),
            'title' => 'Bonus',
            'expires_in_seconds' => 3600,
        ]);
        self::$link = $link;
        self::assertNotSame('', $link->claim_token);
        $ob->payoutLinks->get(['link_id' => $link->link_id]);
        self::assertNotEmpty($ob->payoutLinks->list(['limit' => 5])->items());
        self::$pub->payoutLinks->getPayoutClaim($link->claim_token);
        self::$pub->payoutLinks->claimPayout($link->claim_token, ['address' => self::ADDRESS]);

        $second = $ob->payoutLinks->create([
            'amount' => '5', 'currency' => 'USDT', 'network' => 'tron', 'reference' => self::uniqueId('sw-pl2'),
        ]);
        $ob->payoutLinks->cancel(['link_id' => $second->link_id]);
        $batch = $ob->payoutLinks->createBatch(['items' => [[
            'amount' => '5', 'currency' => 'USDT', 'network' => 'tron', 'reference' => self::uniqueId('sw-plb'),
        ]]]);
        self::assertTrue($batch->items[0]->ok);
    }

    /** @depends testBootstrap */
    public function testPaymentLinks(): void
    {
        $ob = self::$ob;
        $created = $ob->paymentLinks->create([
            'title' => 'Tip', 'amount_mode' => 'fixed', 'currency' => 'USDT', 'amount_fixed' => '10', 'pinned_network' => 'tron',
        ]);
        $ob->paymentLinks->get(['link_id' => $created->link_id]);
        self::assertNotEmpty($ob->paymentLinks->list()->items());
        self::$pub->checkout->getPublicPaymentLink($created->link_id);
        self::$pub->checkout->paymentLink($created->link_id, ['currency' => 'USDT', 'network' => 'tron']);
        $ob->paymentLinks->toggle(['link_id' => $created->link_id, 'active' => false]);
        $this->addToAssertionCount(1);
    }

    /** @depends testBootstrap */
    public function testSplitsAndSettings(): void
    {
        $ob = self::$ob;
        $rule = $ob->splits->createRule(['percent' => '10', 'address' => self::ADDRESS, 'network' => 'tron', 'note' => 'partner']);
        $ob->splits->listRules();
        $ob->splits->setConfig(['refund_hold_seconds' => 3600]);
        $ob->splits->getConfig();
        self::assertTrue($ob->splits->setRecipientOptIn(['enabled' => true])->enabled);
        self::assertTrue($ob->splits->getRecipientOptIn()->enabled);
        $ob->splits->deleteRule(['rule_id' => $rule->rule_id]);

        $ob->settings->setDiscount(['currency' => 'USDT', 'network' => 'tron', 'discount_percent' => 2]);
        $ob->settings->listDiscounts();
        $ob->settings->setAccuracy(['enabled' => true, 'accuracy_percent' => 2]);
        $ob->settings->getAccuracy();
        $ob->settings->setAutoRefund(['overpay' => true, 'underpay' => false]);
        $ob->settings->getAutoRefund();
        $ob->settings->setAcceptedCurrencies(['accepted' => [['currency' => 'USDT', 'network' => 'tron']]]);
        $ob->settings->listAcceptedCurrencies();
        $ob->settings->setPaymentFeeConfig(['payer_pays_percent' => 50]);
        $ob->settings->getPaymentFeeConfig();
        $ob->settings->setAutoWithdrawRule(['currency' => 'USDT', 'network' => 'tron', 'address' => self::ADDRESS, 'min_amount' => '100']);
        $ob->settings->listAutoWithdrawRules();
        $ob->settings->deleteAutoWithdrawRule(['currency' => 'USDT']);

        $ob->apiAllowlist->addEntry(['cidr' => '203.0.113.0/24']);
        $ob->apiAllowlist->list();
        $ob->apiAllowlist->setEnabled(['enabled' => false]);
        $ob->apiAllowlist->removeEntry(['cidr' => '203.0.113.0/24']);
        $this->addToAssertionCount(1);
    }

    /** @depends testBootstrap */
    public function testWebhooksAndSandboxInspector(): void
    {
        $ob = self::$ob;
        $ob->webhooks->register(['url' => self::hookUrl()]);
        self::assertNotSame('', $ob->webhooks->rotateSecret()->secret);
        $ob->webhooks->listDeliveries(['limit' => 5]);
        $this->accept(static fn () => $ob->webhooks->sendTestPayment([
            'url_callback' => self::hookUrl(), 'currency' => 'USDT', 'network' => 'tron', 'status' => 'paid',
        ]));
        $this->accept(static fn () => $ob->webhooks->sendLegacyTest(['url' => self::hookUrl(), 'status' => 'paid']));
        $ob->sandbox->listWebhooks()->items();
    }

    /** @depends testBootstrap */
    public function testWalletsAndTransfers(): void
    {
        // Refused for a dev store, as documented; the point is that the SDK's shapes are accepted.
        $ob = self::$ob;
        $this->accept(static fn () => $ob->wallets->create(['currency' => 'USDT', 'network' => 'tron', 'order_id' => self::uniqueId('sw-w')]));
        $this->accept(static fn () => $ob->wallets->getQr(['address' => self::ADDRESS]));
        $this->accept(static fn () => $ob->wallets->block(['address' => self::ADDRESS]));
        $this->accept(static fn () => $ob->payouts->transferToPersonal(['amount' => '5', 'currency' => 'USDT']));
        $this->addToAssertionCount(1);
    }

    /** @depends testBootstrap */
    public function testDocuments(): void
    {
        if (!self::$docsEnabled) {
            self::markTestSkipped('this stand has no document renderer');
        }
        $ob = self::$ob;
        self::assertStringContainsString('pdf', $ob->documents->getStatement()->contentType);
        self::assertGreaterThan(0, $ob->documents->getFees()->size());
        $ob->documents->getLedger();
        if (self::$link !== null) {
            $cheque = $ob->documents->getPayoutLinkCheque(['claim_token' => self::$link->claim_token, 'lang' => 'en']);
            self::assertStringContainsString('pdf', $cheque->contentType);
        }
        $job = $ob->documents->asJob(static fn (Documents $d) => $d->createJob([
            'kind' => 'statement', 'format' => 'csv', 'lang' => 'en', 'from' => '2026-01-01', 'to' => '2026-08-25',
        ]));
        self::assertSame($job->id, $ob->documents->getJob(['job_id' => $job->id])->job_id);
        $this->accept(static fn () => $job->download());
    }

    /** @depends testBootstrap */
    public function testSandboxResetLast(): void
    {
        self::assertGreaterThanOrEqual(0, self::$ob->sandbox->reset()->invoices_cancelled);
    }
}
