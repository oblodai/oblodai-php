<?php

declare(strict_types=1);

/**
 * Send money out: check the price, dry-run the payout, then create it.
 *
 * Every route here needs the PAYOUT key. A live merchant has two pairs and must set both, since the
 * balance and the quote are read with the payment key; a sandbox key is both kinds at once, so
 * setting only OBLODAI_PAYOUT_* is enough there.
 *
 * Run: OBLODAI_PAYOUT_PUBLIC_ID=… OBLODAI_PAYOUT_SECRET=… php examples/send-payout.php
 */

require __DIR__ . '/_bootstrap.php';

use Oblodai\Contract\Enum\Network;
use Oblodai\Core\RequestOptions;
use Oblodai\Exception\OblodaiException;
use Oblodai\Helper\Money;

$oblodai = example_client(['OBLODAI_PAYOUT_PUBLIC_ID', 'OBLODAI_PAYOUT_SECRET']);

$address = 'TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx';
$amount = '10';

// What will it cost, and does the balance cover it?
try {
    $quote = $oblodai->payouts->calculate(['amount' => $amount, 'currency' => 'USDT', 'network' => 'tron']);
} catch (OblodaiException $err) {
    example_fail('could not price the payout', $err);
}
printf("commission %s, total debited %s (%s pays the network fee)\n", $quote->commission ?? '-', $quote->payer_amount ?? '-', $quote->fee_bearer->value);

try {
    foreach ($oblodai->account->balance()->merchant as $entry) {
        if ($entry->currency === 'USDT') {
            printf("balance    %s USDT\n", $entry->balance);
            if (Money::compare($entry->balance, $quote->payer_amount ?? $amount) < 0) {
                example_die('balance is short — top up first');
            }
        }
    }

    // Every check the real call makes, without reserving or sending anything. `order_id` is
    // optional here: a dry run needs no reference.
    $check = $oblodai->payouts->validate([
        'amount' => $amount, 'currency' => 'USDT', 'network' => 'tron', 'address' => $address,
    ]);
} catch (OblodaiException $err) {
    example_fail('could not check the payout', $err);
}
if (!$check->valid) {
    example_die('the gateway would refuse this payout');
}

try {
    // The SDK generates one Idempotency-Key per call and reuses it on every retry, so a timeout
    // can never produce a second payout. Pass your own key to stay idempotent across restarts.
    $payout = $oblodai->payouts->create(
        [
            'amount' => $amount,
            'currency' => 'USDT',
            'network' => Network::Tron->value,
            'address' => $address,
            'order_id' => 'payout-' . time(),
        ],
        new RequestOptions(idempotencyKey: 'payout-' . date('Y-m-d') . '-batch-7')
    );
} catch (OblodaiException $err) {
    // `retryable` is the gateway's own classification — the SDK already retried what it could.
    // Codes worth branching on here: payout.insufficient_funds, payout.funds_maturing (both
    // retryable), payout.bad_address, payout.memo_required, merchant.wrong_key_kind.
    example_fail('payout refused', $err);
}

printf("payout %s → %s\n", $payout->uuid, $payout->status->value);
printf("debited %s %s, commission %s\n", $payout->payer_amount, $payout->currency, $payout->commission);
printf("txid    %s\n", $payout->txid !== '' ? $payout->txid : '(not broadcast yet)');
