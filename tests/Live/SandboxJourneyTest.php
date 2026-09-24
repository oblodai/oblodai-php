<?php

declare(strict_types=1);

namespace Oblodai\Tests\Live;

use Oblodai\Core\RequestOptions;
use Oblodai\Exception\IdempotencyConflictException;
use Oblodai\Exception\OblodaiException;
use Oblodai\Generated\Enum\PaymentStatus;
use Oblodai\Generated\Model\PaymentView;
use Oblodai\Helper\Status;
use Oblodai\Oblodai;

/**
 * The money path against a real gateway: signature, envelope, idempotency, webhooks.
 *
 * @group live
 */
final class SandboxJourneyTest extends LiveTestCase
{
    private static Oblodai $ob;

    private static PaymentView $invoice;

    public function testBootstrap(): void
    {
        self::$ob = self::onboardSandbox('sdk-live');
        self::$invoice = self::$ob->payments->create([
            'amount' => '25',
            'currency' => 'USDT',
            'network' => 'tron',
            'order_id' => self::uniqueId('sdk-live'),
        ]);
        self::assertSame(PaymentStatus::Created, self::$invoice->status);
    }

    public function testReadsPublicCatalogDataWithoutCredentials(): void
    {
        self::assertNotEmpty(self::anonymous()->checkout->listCurrencies()->currencies);
    }

    /** @depends testBootstrap */
    public function testReadsTheInvoiceBackAndListsIt(): void
    {
        $invoice = self::$invoice;
        self::assertSame($invoice->uuid, self::$ob->payments->getInfo(['order_id' => (string) $invoice->order_id])->uuid);

        $found = false;
        foreach (self::$ob->payments->listHistory(['limit' => 5])->first() as $payment) {
            $found = $found || $payment->uuid === $invoice->uuid;
        }
        self::assertTrue($found);

        // A signed GET with a query string: the signature covers path + raw query.
        self::assertGreaterThanOrEqual(0, count(self::$ob->sandbox->listWebhooks(limit: 5)->items()));
    }

    /** @depends testBootstrap */
    public function testReplaysAnIdempotentCreateAndRefusesAReusedKey(): void
    {
        $key = self::uniqueId('sdk-idem');
        $body = ['amount' => '5', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => $key . '-o'];
        $first = self::$ob->payments->create($body, new RequestOptions(idempotencyKey: $key));
        $second = self::$ob->payments->create($body, new RequestOptions(idempotencyKey: $key));
        self::assertSame($first->uuid, $second->uuid);

        try {
            self::$ob->payments->create(
                ['amount' => '2', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => $key . '-o2'],
                new RequestOptions(idempotencyKey: $key)
            );
            self::fail('expected an IdempotencyConflictException');
        } catch (IdempotencyConflictException $e) {
            self::assertSame('idempotency.key_reused', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    /** @depends testBootstrap */
    public function testDepositThenPayout(): void
    {
        $invoice = self::$invoice;
        self::$ob->sandbox->simulateDeposit([
            'invoice_id' => $invoice->uuid,
            'amount' => '25',
            'confirmations' => 20,
            'txid' => self::uniqueId('sdk-tx'),
        ]);
        self::assertTrue(Status::isPaymentPaid(self::$ob->payments->getInfo(['uuid' => $invoice->uuid])->status));

        self::$ob->sandbox->faucet(['asset' => 'USDT', 'amount' => '100']);
        $usdt = array_filter(
            self::$ob->account->getBalance()->balance->merchant,
            static fn ($entry): bool => $entry->currency === 'USDT'
        );
        self::assertNotSame([], $usdt);

        $payout = ['amount' => '10', 'currency' => 'USDT', 'network' => 'tron', 'address' => self::ADDRESS];
        self::assertSame('USDT', self::$ob->payouts->calculate(['amount' => '10', 'currency' => 'USDT', 'network' => 'tron'])->currency);
        self::assertTrue(self::$ob->payouts->validate($payout)->valid);
        $created = self::$ob->payouts->create($payout + ['order_id' => self::uniqueId('sdk-po')]);
        self::assertSame($created->order_id, self::$ob->payouts->getInfo(['uuid' => $created->uuid])->order_id);
    }

    /** @depends testBootstrap */
    public function testClassifiesADomainRefusalWithTheGatewaysOwnFlags(): void
    {
        try {
            self::$ob->payouts->create([
                'amount' => '999999',
                'currency' => 'USDT',
                'network' => 'tron',
                'address' => self::ADDRESS,
                'order_id' => self::uniqueId('sdk-big'),
            ]);
            self::fail('expected an OblodaiException');
        } catch (OblodaiException $e) {
            self::assertSame('payout.insufficient_funds', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            self::assertNotNull($e->requestId);
        }
    }
}
