<?php

declare(strict_types=1);

namespace Oblodai\Tests\Live;

use Oblodai\Contract\Enum\PayoutLinkStatus;
use Oblodai\Contract\Enum\WebhookKind;
use Oblodai\Contract\Model\Payment;
use Oblodai\Contract\Model\PayoutLink;
use Oblodai\Contract\Model\SplitRule;
use Oblodai\Contract\Model\WebhookDelivery;
use Oblodai\Exception\NotFoundException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Oblodai;

/**
 * Every namespace against a real core. The point is not the business outcome but that the bodies
 * the SDK sends are accepted (no 400 from our own shapes) and the bodies that come back decode (no
 * ContractException). Routes that need a subsystem the stand may lack (documents, email) are
 * probed and skipped when the core reports them disabled.
 *
 * @group live
 */
final class SweepTest extends LiveTestCase
{
    private static Oblodai $ob;
    private static Oblodai $pub;
    private static ?Payment $invoice = null;
    private static ?PayoutLink $link = null;
    private static bool $docsEnabled = true;
    private const ADDRESS = 'TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx';

    private static function hookUrl(): string
    {
        $hook = getenv('OBLODAI_LIVE_HOOK_URL');

        return is_string($hook) && $hook !== '' ? $hook : 'http://127.0.0.1:8096/hook';
    }

    public function testBootstrap(): void
    {
        self::$ob = self::onboardSandbox('sweep');
        self::$pub = self::anonymous();
        self::$ob->sandbox->faucet(['asset' => 'USDT', 'amount' => '1000']);
        // a per-invoice url_callback needs a registered endpoint (it signs with its secret)
        self::$ob->webhooks->register(self::hookUrl());
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
            self::$ob->documents->balanceCertificate();
        } catch (NotFoundException $err) {
            if ($err->errorCode === 'document.disabled') {
                self::$docsEnabled = false;
            }
        } catch (OblodaiException) {
            self::$docsEnabled = false;
        }
    }

    /** @depends testBootstrap */
    public function testCatalogAndAccount(): void
    {
        self::assertNotEmpty(self::$pub->catalog->currencies()->currencies);
        foreach (self::$pub->catalog->exchangeRates(['currency_from' => 'BTC'])->items() as $rate) {
            self::assertSame('BTC', $rate->from);
        }
        foreach (self::$ob->account->balance()->merchant as $entry) {
            self::assertNotSame('', $entry->currency);
        }
        self::assertNotSame('', self::$ob->account->referral()->code);
        self::assertFalse(self::$ob->account->vrcs(false)->enabled);
        self::assertFalse(self::$ob->account->vrcs()->enabled);
    }

    /** @depends testBootstrap */
    public function testPaymentsLookupsQrServicesPublicCheckoutBatch(): void
    {
        $invoice = self::$invoice;
        self::assertNotNull($invoice);

        self::assertSame($invoice->uuid, self::$ob->payments->get($invoice->uuid)->uuid);
        // Sandbox invoices carry a synthetic `sandbox:` address, which the core deliberately does
        // not render into a QR — the fields come back empty. A real invoice returns a data URI.
        $qr = self::$ob->payments->qr($invoice->uuid)->image;
        self::assertTrue($qr === '' || str_starts_with($qr, 'data:image'), 'unexpected QR payload');
        self::assertNotEmpty(self::$ob->payments->services(['limit' => 5])->items());
        self::assertSame('created', self::$pub->payments->publicView($invoice->uuid)->status->value);
        $publicQr = self::$pub->payments->publicQr($invoice->uuid)->image;
        self::assertTrue($publicQr === '' || str_starts_with($publicQr, 'data:image'));

        $multi = self::$ob->payments->create([
            'amount' => '10',
            'currency' => 'USDT',
            'order_id' => self::uniqueId('sw-multi'),
        ]);
        $selected = self::$pub->payments->select($multi->uuid, ['currency' => 'USDT', 'network' => 'tron']);
        self::assertSame('tron', $selected->network);

        $this->accept(fn () => self::$ob->payments->resend($invoice->uuid));
        $this->accept(fn () => self::$ob->payments->sendEmail(['uuid' => $invoice->uuid]));

        $batch = self::$ob->payments->batch([
            'on_error' => 'continue',
            'payments' => [
                ['amount' => '3', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => self::uniqueId('sw-b')],
            ],
        ]);
        self::assertNotSame('', $batch->batch_id);
        self::assertSame($batch->batch_id, self::$ob->batches->info(['batch_id' => $batch->batch_id])->batch_id);

        $toCancel = self::$ob->payments->create([
            'amount' => '1',
            'currency' => 'USDT',
            'network' => 'tron',
            'order_id' => self::uniqueId('sw-c'),
        ]);
        self::assertSame('cancelled', self::$ob->payments->cancel($toCancel->uuid)->status->value);

        $seen = 0;
        foreach (self::$ob->payments->history(['limit' => 2]) as $payment) {
            self::assertNotSame('', $payment->uuid);
            if (++$seen >= 5) {
                break; // the iterator walks pages lazily; five items prove it crosses the first
            }
        }
        self::assertGreaterThan(0, $seen);
    }

    /** @depends testPaymentsLookupsQrServicesPublicCheckoutBatch */
    public function testDepositRefundResolveAndRefundBatch(): void
    {
        $invoice = self::$invoice;
        self::assertNotNull($invoice);

        self::$ob->sandbox->deposit([
            'invoice_id' => $invoice->uuid,
            'amount' => '25',
            'confirmations' => 20,
            'txid' => self::uniqueId('sw-tx'),
        ]);
        self::assertContains(self::$ob->payments->get($invoice->uuid)->status->value, ['paid', 'confirm_check']);

        $this->accept(fn () => self::$ob->refunds->create([
            'uuid' => $invoice->uuid,
            'address' => self::ADDRESS,
            'amount' => '5',
            'reference' => self::uniqueId('sw-r'),
        ]));
        $this->accept(fn () => self::$ob->refunds->resolve(['uuid' => $invoice->uuid, 'action' => 'accept']));
        $this->accept(fn () => self::$ob->refunds->batch([
            'refunds' => [[
                'uuid' => $invoice->uuid,
                'address' => self::ADDRESS,
                'amount' => '1',
                'reference' => self::uniqueId('sw-rb'),
            ]],
        ]));
        self::assertContains(
            self::$ob->payments->get($invoice->uuid)->status->value,
            ['paid', 'paid_over', 'confirm_check', 'wrong_amount']
        );
    }

    /** @depends testBootstrap */
    public function testPayouts(): void
    {
        self::assertSame(
            'USDT',
            self::$ob->payouts->calculate(['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])->currency
        );
        self::assertTrue(self::$ob->payouts->validate([
            'amount' => '10', 'currency' => 'USDT', 'network' => 'tron', 'address' => self::ADDRESS,
        ])->valid);

        $orderId = self::uniqueId('sw-po');
        $payout = self::$ob->payouts->create([
            'amount' => '10', 'currency' => 'USDT', 'network' => 'tron',
            'address' => self::ADDRESS, 'order_id' => $orderId,
        ]);
        self::assertSame($payout->uuid, self::$ob->payouts->get(['order_id' => $orderId])->uuid);
        $this->accept(fn () => self::$ob->payouts->cancel($payout->uuid));
        $this->accept(fn () => self::$ob->payouts->approve($payout->uuid));

        $mass = self::$ob->payouts->mass([
            'payouts' => [[
                'amount' => '1', 'currency' => 'USDT', 'network' => 'tron',
                'address' => self::ADDRESS, 'order_id' => self::uniqueId('sw-m'),
            ]],
        ]);
        self::assertSame(0, $mass[0]->idx);

        $batch = self::$ob->payouts->batch([
            'payouts' => [[
                'amount' => '1', 'currency' => 'USDT', 'network' => 'tron',
                'address' => self::ADDRESS, 'order_id' => self::uniqueId('sw-pb'),
            ]],
        ]);
        self::assertNotSame('', $batch->batch_id);
        self::assertNotEmpty(self::$ob->payouts->services()->items());
        self::assertTrue(self::$ob->payouts->setFeeConfig(['fee_on_recipient' => true])->fee_on_recipient);
        self::assertTrue(self::$ob->payouts->getFeeConfig()->fee_on_recipient);
        self::assertTrue(self::$ob->payouts->setRefundFeeConfig(['fee_on_customer' => true])->fee_on_customer);
        self::assertTrue(self::$ob->payouts->getRefundFeeConfig()->fee_on_customer);
        foreach (self::$ob->payouts->history(['kind' => 'refund', 'limit' => 5])->items() as $refund) {
            self::assertTrue($refund->is_refund);
        }
    }

    /** @depends testBootstrap */
    public function testPayoutLinks(): void
    {
        self::$link = self::$ob->payoutLinks->create([
            'amount' => '5',
            'currency' => 'USDT',
            'network' => 'tron',
            'reference' => self::uniqueId('sw-pl'),
            'title' => 'Bonus',
            'expires_in_seconds' => 3600,
        ]);
        $link = self::$link;
        self::assertNotNull($link->claim_token);
        self::assertTrue(self::$ob->payoutLinks->get($link->link_id)->status->is(PayoutLinkStatus::Funded));
        self::assertNotEmpty(self::$ob->payoutLinks->list(['limit' => 5])->items());
        self::assertTrue(self::$pub->payoutLinks->claimPreview((string) $link->claim_token)->claimable);

        $claimed = self::$pub->payoutLinks->claim((string) $link->claim_token, ['address' => self::ADDRESS]);
        self::assertNotSame('', $claimed->payout_id);

        $second = self::$ob->payoutLinks->create([
            'amount' => '1', 'currency' => 'USDT', 'network' => 'tron', 'reference' => self::uniqueId('sw-pl2'),
        ]);
        self::assertTrue(self::$ob->payoutLinks->cancel($second->link_id)->status->is(PayoutLinkStatus::Cancelled));

        $batch = self::$ob->payoutLinks->batch([
            'items' => [[
                'amount' => '1', 'currency' => 'USDT', 'network' => 'tron', 'reference' => self::uniqueId('sw-plb'),
            ]],
        ]);
        self::assertTrue($batch[0]->ok);
    }

    /** @depends testBootstrap */
    public function testPaymentLinks(): void
    {
        $created = self::$ob->paymentLinks->create([
            'title' => 'Tip',
            'amount_mode' => 'fixed',
            'currency' => 'USDT',
            'amount_fixed' => '10',
            'pinned_network' => 'tron',
        ]);
        self::assertNotSame('', $created->link_id);
        self::assertTrue(self::$ob->paymentLinks->get($created->link_id)->active);
        self::assertNotEmpty(self::$ob->paymentLinks->list()->items());
        self::assertSame('fixed', self::$pub->paymentLinks->publicView($created->link_id)->amount_mode->value);
        self::assertNotSame(
            '',
            self::$pub->paymentLinks->checkout($created->link_id, ['currency' => 'USDT', 'network' => 'tron'])->uuid
        );
        self::assertFalse(self::$ob->paymentLinks->toggle($created->link_id, false)->active);
    }

    /** @depends testBootstrap */
    public function testSplitsAndSettings(): void
    {
        $rule = self::$ob->splits->createRule([
            'percent' => '10', 'address' => self::ADDRESS, 'network' => 'tron', 'note' => 'partner',
        ]);
        $ruleIds = array_map(static fn (SplitRule $r): string => $r->rule_id, self::$ob->splits->listRules()->items());
        self::assertContains($rule->rule_id, $ruleIds);
        self::assertSame(3600, self::$ob->splits->setConfig(['refund_hold_seconds' => 3600])->refund_hold_seconds);
        self::assertSame(3600, self::$ob->splits->getConfig()->refund_hold_seconds);
        self::assertTrue(self::$ob->splits->setOptIn(true)->enabled);
        self::assertTrue(self::$ob->splits->getOptIn()->enabled);
        self::assertTrue(self::$ob->splits->deleteRule($rule->rule_id)->ok);

        self::assertSame(2, self::$ob->settings->setDiscount([
            'currency' => 'USDT', 'network' => 'tron', 'discount_percent' => 2,
        ])->discount_percent);
        self::assertNotEmpty(self::$ob->settings->listDiscounts()->items());
        self::assertTrue(self::$ob->settings->setAccuracy(['enabled' => true, 'accuracy_percent' => 2])->enabled);
        self::assertTrue(self::$ob->settings->getAccuracy()->enabled);
        self::assertTrue(self::$ob->settings->setAutoRefund(['overpay' => true, 'underpay' => false])->overpay);
        self::assertTrue(self::$ob->settings->getAutoRefund()->overpay);
        self::assertTrue(self::$ob->settings->setAccepted([
            'accepted' => [['currency' => 'USDT', 'network' => 'tron']],
        ])->ok);
        foreach (self::$ob->settings->listAccepted()->items() as $method) {
            self::assertNotSame('', $method->currency);
        }
        self::assertSame(50, self::$ob->settings->setPaymentFeeConfig(['payer_pays_percent' => 50])->payer_pays_percent);
        self::assertSame(50, self::$ob->settings->getPaymentFeeConfig()->payer_pays_percent);

        self::assertNotEmpty(self::$ob->settings->setAutoWithdraw([
            'currency' => 'USDT', 'network' => 'tron', 'address' => self::ADDRESS, 'min_amount' => '100',
        ]));
        self::assertNotEmpty(self::$ob->settings->listAutoWithdraw());
        self::assertSame([], self::$ob->settings->deleteAutoWithdraw('USDT'));

        self::assertContains('203.0.113.0/24', self::$ob->settings->addApiAllowlist('203.0.113.0/24')->items);
        self::assertContains('203.0.113.0/24', self::$ob->settings->listApiAllowlist()->items);
        self::assertFalse(self::$ob->settings->enableApiAllowlist(false)->enabled);
        self::assertNotContains('203.0.113.0/24', self::$ob->settings->removeApiAllowlist('203.0.113.0/24')->items);
    }

    /** @depends testBootstrap */
    public function testWebhooksAndSandboxInspector(): void
    {
        $endpoint = self::$ob->webhooks->register(self::hookUrl());
        self::assertNotSame('', $endpoint->endpoint_id);
        $rotated = self::$ob->webhooks->rotateSecret();
        self::assertNotSame('', $rotated->secret);
        self::assertLessThanOrEqual(5, count(self::$ob->webhooks->deliveries(['limit' => 5])->items()));

        $this->accept(fn () => self::$ob->webhooks->test(WebhookKind::Payment, [
            'url_callback' => self::hookUrl(), 'currency' => 'USDT', 'network' => 'tron', 'status' => 'paid',
        ]));
        $this->accept(fn () => self::$ob->webhooks->testLegacy(['url' => self::hookUrl(), 'status' => 'paid']));

        $inspector = self::$ob->sandbox->webhooks(['limit' => 5]);
        self::assertLessThanOrEqual(5, count($inspector->items()));
        foreach ($inspector->items() as $delivery) {
            /** @var WebhookDelivery $delivery */
            if (in_array($delivery->status->value, ['delivered', 'dead'], true)) {
                $this->accept(fn () => self::$ob->sandbox->replay($delivery->id));

                break;
            }
        }
        self::assertSame($endpoint->url, self::hookUrl());
    }

    /** @depends testBootstrap */
    public function testWalletsAndTransfers(): void
    {
        // A dev store has no static wallets and no personal balance: refusals here are documented.
        $this->accept(fn () => self::$ob->wallets->create([
            'currency' => 'USDT', 'network' => 'tron', 'order_id' => self::uniqueId('sw-w'),
        ]));
        $this->accept(fn () => self::$ob->wallets->qr(self::ADDRESS));
        $this->accept(fn () => self::$ob->wallets->block(['address' => self::ADDRESS]));
        $this->accept(fn () => self::$ob->transfers->toPersonal(['amount' => '1', 'currency' => 'USDT']));
        // The refusals above are the documented behaviour for a dev store; what matters is that the
        // SDK's own request and response shapes were accepted (accept() re-throws 400s and
        // ContractExceptions).
        self::assertNotSame('', self::$ob->account->referral()->code);
    }

    /** @depends testPayoutLinks */
    public function testDocumentsWhenTheStandHasARenderer(): void
    {
        if (!self::$docsEnabled) {
            self::markTestSkipped('this stand has no document renderer');
        }
        $statement = self::$ob->documents->statement(['from' => '2026-01-01', 'to' => '2026-12-31', 'lang' => 'en']);
        self::assertStringContainsString('pdf', $statement->contentType);
        self::assertGreaterThan(0, $statement->size());
        self::assertGreaterThan(0, self::$ob->documents->feeSchedule()->size());
        self::assertMatchesRegularExpression('/csv|pdf/', self::$ob->documents->ledger(['format' => 'csv'])->contentType);

        $link = self::$link;
        if ($link !== null && $link->claim_token !== null) {
            self::assertStringContainsString(
                'pdf',
                self::$ob->payoutLinks->cheque(['claim_token' => $link->claim_token, 'lang' => 'en'])->contentType
            );
        }

        $job = self::$ob->documents->createJob([
            'kind' => 'statement', 'format' => 'csv', 'lang' => 'en', 'from' => '2026-01-01', 'to' => '2026-08-25',
        ]);
        self::assertSame($job->job_id, self::$ob->documents->jobInfo($job->job_id)->job_id);
        $this->accept(fn () => self::$ob->documents->jobFile($job->job_id));

        $invoice = self::$invoice;
        self::assertNotNull($invoice);
        $documentUrl = self::$ob->payments->get($invoice->uuid)->document_url;
        if ($documentUrl !== '') {
            $parts = parse_url($documentUrl);
            $segments = explode('/', trim((string) ($parts['path'] ?? ''), '/'));
            parse_str((string) ($parts['query'] ?? ''), $query);
            self::assertStringContainsString('pdf', self::$pub->documents->download(
                $segments[2] ?? '',
                $segments[3] ?? '',
                ['exp' => $query['exp'] ?? '', 'sig' => $query['sig'] ?? '']
            )->contentType);
        }
    }

    /** @depends testWalletsAndTransfers */
    public function testSandboxResetLast(): void
    {
        self::assertGreaterThanOrEqual(0, self::$ob->sandbox->reset()->invoices_cancelled);
    }
}
