<?php

declare(strict_types=1);

/**
 * The whole money path against the developer sandbox: create an invoice, simulate the deposit that
 * pays it, top the balance up from the faucet and send a payout back out. Nothing here touches a
 * real chain — sandbox keys (`test_…`) drive a chainless copy of the gateway.
 *
 * Run: OBLODAI_PUBLIC_ID=test_… OBLODAI_SECRET=… php examples/sandbox-journey.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Oblodai\Helper\Status;
use Oblodai\Oblodai;

$oblodai = new Oblodai(
    baseUrl: getenv('OBLODAI_BASE_URL') ?: null,
    allowInsecureBaseUrl: true,
);

$orderId = 'sandbox-' . time();
$invoice = $oblodai->payments->create([
    'amount' => '25', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => $orderId,
]);
printf("invoice %s — %s %s to %s\n", $invoice->uuid, $invoice->payer_amount, $invoice->payer_currency, $invoice->address);

// The buyer pays. `confirmations` deep enough to credit; repeat the same txid to add confirmations.
$oblodai->sandbox->deposit([
    'invoice_id' => $invoice->uuid,
    'amount' => '25',
    'confirmations' => 20,
    'txid' => 'sandbox-tx-' . time(),
]);

$paid = $oblodai->payments->info($invoice->uuid);
printf("status  %s (paid: %s)\n", $paid->status->value, Status::isPaymentPaid($paid->status) ? 'yes' : 'no');
printf("credited %s %s after %s commission\n", $paid->merchant_amount, $paid->payer_currency, $paid->commission);

// Test funds, then money back out.
$oblodai->sandbox->faucet(['asset' => 'USDT', 'amount' => '100']);
foreach ($oblodai->account->balance()->merchant as $entry) {
    printf("balance %s %s\n", $entry->balance, $entry->currency);
}

$payout = $oblodai->payouts->create([
    'amount' => '10',
    'currency' => 'USDT',
    'network' => 'tron',
    'address' => 'TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx',
    'order_id' => 'sandbox-payout-' . time(),
]);
printf("payout  %s → %s\n", $payout->uuid, $payout->status->value);

// Every delivery the sandbox attempted, with its payload — the webhook inspector.
foreach ($oblodai->sandbox->webhooks(['limit' => 10])->items() as $delivery) {
    printf("webhook %s %s (%d attempts)\n", $delivery->event_type, $delivery->status->value, $delivery->attempts);
}

// Start over: cancels open invoices and zeroes the balances.
$reset = $oblodai->sandbox->reset();
printf("reset: %d invoices cancelled, %d balances zeroed\n", $reset->invoices_cancelled, $reset->balances_zeroed);
