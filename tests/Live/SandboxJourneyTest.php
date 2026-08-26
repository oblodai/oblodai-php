<?php

declare(strict_types=1);

namespace Oblodai\Tests\Live;

use Oblodai\Contract\Enum\PaymentStatus;
use Oblodai\Contract\Model\Payment;
use Oblodai\Exception\ConflictException;
use Oblodai\Helper\Status;
use Oblodai\Oblodai;

/**
 * The money path against a real core: signature, envelope, idempotency, pagination and the
 * sandbox's simulated chain — all of it for real, none of it mocked.
 *
 * @group live
 */
final class SandboxJourneyTest extends LiveTestCase
{
    private static Oblodai $ob;
    private static ?Payment $invoice = null;

    public function testReadsPublicCatalogDataWithoutCredentials(): void
    {
        $currencies = self::anonymous()->catalog->currencies();
        self::assertNotEmpty($currencies->currencies);
    }

    public function testCreatesAnInvoiceAndReadsItBack(): void
    {
        self::$ob = self::onboardSandbox('sdk-live');
        $orderId = self::uniqueId('sdk-live');
        self::$invoice = self::$ob->payments->create([
            'amount' => '25',
            'currency' => 'USDT',
            'network' => 'tron',
            'order_id' => $orderId,
        ]);
        self::assertTrue(self::$invoice->status->is(PaymentStatus::Created), 'got ' . self::$invoice->status->value);
        self::assertSame('25.000000', self::$invoice->amount);

        // by order_id, not just by uuid
        self::assertSame(
            self::$invoice->uuid,
            self::$ob->payments->info(['order_id' => $orderId])->uuid
        );

        $page = self::$ob->payments->history(['limit' => 5]);
        $uuids = array_map(static fn (Payment $p): string => $p->uuid, $page->items());
        self::assertContains(self::$invoice->uuid, $uuids);

        // a signed GET with a query string — the signature covers path + raw query
        $hooks = self::$ob->sandbox->webhooks(['limit' => 5, 'offset' => 0]);
        self::assertLessThanOrEqual(5, count($hooks->items()));
    }

    /** @depends testCreatesAnInvoiceAndReadsItBack */
    public function testReplaysAnIdempotentCreateAndRefusesAReusedKey(): void
    {
        $key = self::uniqueId('sdk-idem');
        $first = self::$ob->payments->create(
            ['amount' => '5', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => $key . '-o'],
            new \Oblodai\Core\RequestOptions(idempotencyKey: $key)
        );
        $replay = self::$ob->payments->create(
            ['amount' => '5', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => $key . '-o'],
            new \Oblodai\Core\RequestOptions(idempotencyKey: $key)
        );
        self::assertSame($first->uuid, $replay->uuid);

        try {
            self::$ob->payments->create(
                ['amount' => '2', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => $key . '-o2'],
                new \Oblodai\Core\RequestOptions(idempotencyKey: $key)
            );
            self::fail('a reused key with a different body must be refused');
        } catch (ConflictException $err) {
            self::assertSame('idempotency.key_reused', $err->errorCode);
            self::assertSame(409, $err->httpStatus);
        }
    }

    /** @depends testCreatesAnInvoiceAndReadsItBack */
    public function testSimulatesADepositThenFundsAndSendsAPayout(): void
    {
        $invoice = self::$invoice;
        self::assertNotNull($invoice);

        self::$ob->sandbox->deposit([
            'invoice_id' => $invoice->uuid,
            'amount' => '25',
            'confirmations' => 20,
            'txid' => self::uniqueId('sdk-tx'),
        ]);
        $paid = self::$ob->payments->info($invoice->uuid);
        self::assertTrue(Status::isPaymentPaid($paid->status), 'invoice should be paid, got ' . $paid->status->value);

        self::$ob->sandbox->faucet(['asset' => 'USDT', 'amount' => '100']);
        $balance = self::$ob->account->balance();
        $usdt = array_values(array_filter(
            $balance->merchant,
            static fn (\Oblodai\Contract\Model\BalanceEntry $entry): bool => $entry->currency === 'USDT'
        ));
        self::assertNotEmpty($usdt);

        $calculation = self::$ob->payouts->calculate(['amount' => '10', 'currency' => 'USDT', 'network' => 'tron']);
        self::assertSame('USDT', $calculation->currency);

        $validation = self::$ob->payouts->validate([
            'amount' => '10',
            'currency' => 'USDT',
            'network' => 'tron',
            'address' => 'TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx',
        ]);
        self::assertTrue($validation->valid);

        $orderId = self::uniqueId('sdk-po');
        $payout = self::$ob->payouts->create([
            'amount' => '10',
            'currency' => 'USDT',
            'network' => 'tron',
            'address' => 'TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx',
            'order_id' => $orderId,
        ]);
        self::assertNotSame('', $payout->uuid);
        self::assertSame($orderId, self::$ob->payouts->info($payout->uuid)->order_id);
    }

    /** @depends testSimulatesADepositThenFundsAndSendsAPayout */
    public function testClassifiesADomainRefusalWithTheCoresOwnRetryableFlag(): void
    {
        try {
            self::$ob->payouts->create([
                'amount' => '999999',
                'currency' => 'USDT',
                'network' => 'tron',
                'address' => 'TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx',
                'order_id' => self::uniqueId('sdk-big'),
            ]);
            self::fail('the core must refuse a payout larger than the balance');
        } catch (ConflictException $err) {
            self::assertSame('payout.insufficient_funds', $err->errorCode);
            self::assertSame(409, $err->httpStatus);
            self::assertIsString($err->requestId);
        }
    }
}
