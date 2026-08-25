<?php

declare(strict_types=1);

/**
 * Send money out: check the price, dry-run the payout, then create it. Payout routes need the payout
 * key (`OBLODAI_PAYOUT_PUBLIC_ID` / `OBLODAI_PAYOUT_SECRET`); a sandbox key is both kinds at once.
 *
 * Run: OBLODAI_PUBLIC_ID=… OBLODAI_SECRET=… php examples/send-payout.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Oblodai\Contract\Enum\Network;
use Oblodai\Core\RequestOptions;
use Oblodai\Exception\OblodaiException;
use Oblodai\Helper\Money;
use Oblodai\Oblodai;

$oblodai = new Oblodai(
    baseUrl: getenv('OBLODAI_BASE_URL') ?: null,
    allowInsecureBaseUrl: true,
);

$address = 'TQrY8bkbpXKPt2LZbU8jqfnpFbUSF15sbx';
$amount = '10';

// What will it cost, and does the balance cover it?
$quote = $oblodai->payouts->calculate(['amount' => $amount, 'currency' => 'USDT', 'network' => 'tron']);
printf("commission %s, total debited %s (%s pays the network fee)\n", $quote->commission ?? '-', $quote->payer_amount ?? '-', $quote->fee_bearer->value);

foreach ($oblodai->account->balance()->merchant as $entry) {
    if ($entry->currency === 'USDT') {
        printf("balance    %s USDT\n", $entry->balance);
        if (Money::compare($entry->balance, $quote->payer_amount ?? $amount) < 0) {
            fwrite(STDERR, "balance is short — top up first\n");

            exit(1);
        }
    }
}

// Every check the real call makes, without reserving or sending anything.
$check = $oblodai->payouts->validate([
    'amount' => $amount, 'currency' => 'USDT', 'network' => 'tron', 'address' => $address,
]);
if (!$check->valid) {
    fwrite(STDERR, "the gateway would refuse this payout\n");

    exit(1);
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
    fwrite(STDERR, sprintf(
        "payout refused: %s (%s%s)\n",
        $err->getMessage(),
        $err->errorCode,
        $err->retryable ? ', retryable' : ''
    ));

    exit(1);
}

printf("payout %s → %s\n", $payout->uuid, $payout->status->value);
printf("debited %s %s, commission %s\n", $payout->payer_amount, $payout->currency, $payout->commission);
printf("txid    %s\n", $payout->txid !== '' ? $payout->txid : '(not broadcast yet)');
