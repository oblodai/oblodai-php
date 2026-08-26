<?php

declare(strict_types=1);

/**
 * The whole money path against the developer sandbox: create an invoice, simulate the deposit that
 * pays it, top the balance up from the faucet and send a payout back out. Nothing here touches a
 * real chain — sandbox keys (`test_oblodai_…`) drive a chainless copy of the gateway.
 *
 * Run: OBLODAI_PUBLIC_ID=test_oblodai_… OBLODAI_SECRET=oblodai_test_… php examples/sandbox-journey.php
 */

require __DIR__ . '/_bootstrap.php';

use Oblodai\Exception\OblodaiException;
use Oblodai\Helper\Status;

$oblodai = example_client();

$orderId = 'sandbox-' . time();

try {
    $invoice = $oblodai->payments->create([
        'amount' => '25', 'currency' => 'USDT', 'network' => 'tron', 'order_id' => $orderId,
    ]);
} catch (OblodaiException $err) {
    // A live key gets 403 sandbox.live_key on the helpers below — use a test_oblodai_… key here.
    example_fail('could not create the sandbox invoice', $err);
}
printf("invoice %s — %s %s to %s\n", $invoice->uuid, $invoice->payer_amount, $invoice->payer_currency, $invoice->address);

try {
    // The buyer pays. `confirmations` deep enough to credit; repeat the same txid to add more.
    $oblodai->sandbox->deposit([
        'invoice_id' => $invoice->uuid,
        'amount' => '25',
        'confirmations' => 20,
        'txid' => 'sandbox-tx-' . time(),
    ]);

    $paid = $oblodai->payments->info($invoice->uuid);
    printf("status  %s (paid: %s)\n", $paid->status->value, Status::isPaymentPaid($paid->status) ? 'yes' : 'no');
    printf("credited %s %s after %s commission\n", $paid->merchant_amount, $paid->payer_currency, $paid->commission);

    // Test funds, then money back out. The faucet and the payout need the PAYOUT key; a sandbox
    // key is both kinds at once, so the same pair works for all of it.
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
} catch (OblodaiException $err) {
    example_fail('the sandbox journey stopped', $err);
}
