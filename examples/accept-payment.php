<?php

declare(strict_types=1);

/**
 * Accept a payment: create an invoice, show the payer where to send the money, then poll it once.
 * In production you learn about the state change from a webhook — see webhook-receiver.php — and
 * poll only as a fallback.
 *
 * Run: OBLODAI_PUBLIC_ID=… OBLODAI_SECRET=… php examples/accept-payment.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Oblodai\Contract\Enum\Network;
use Oblodai\Contract\Request\PaymentRequest;
use Oblodai\Exception\OblodaiException;
use Oblodai\Helper\Status;
use Oblodai\Oblodai;

$oblodai = new Oblodai(
    // publicId/secret fall back to OBLODAI_PUBLIC_ID / OBLODAI_SECRET
    baseUrl: getenv('OBLODAI_BASE_URL') ?: null,
    allowInsecureBaseUrl: true, // only needed against a local gateway
);

try {
    $invoice = $oblodai->payments->create(new PaymentRequest(
        amount: '25',                       // decimal string — never a float
        currency: 'USDT',                   // what you price in; a fiat here needs to_currency
        network: Network::Tron,             // omit to let the payer pick on the pay page
        order_id: 'order-' . time(),        // your reference; the invoice is idempotent per order_id
        payer_email: 'buyer@example.com',
        url_callback: 'https://shop.example/oblodai/webhook',
    ));
} catch (OblodaiException $err) {
    fwrite(STDERR, sprintf(
        "could not create the invoice: %s (%s, request %s)\n",
        $err->getMessage(),
        $err->errorCode,
        $err->requestId ?? '-'
    ));

    exit(1);
}

printf("invoice   %s\n", $invoice->uuid);
printf("status    %s\n", $invoice->status->value);        // "created"
printf("pay page  %s\n", $invoice->url);                  // hand this to the buyer …
printf("address   %s\n", $invoice->address);              // … or render the address yourself
printf("send      %s %s\n", $invoice->payer_amount, $invoice->payer_currency);
if ($invoice->destination_tag !== '' || $invoice->memo !== '') {
    printf("tag/memo  %s%s  ← the transfer MUST carry it\n", $invoice->destination_tag, $invoice->memo);
}
printf("expires   %s\n", $invoice->expired_at);

// Later, or from a fallback poller:
$current = $oblodai->payments->info(['order_id' => $invoice->order_id]);
printf(
    "\nnow: %s (paid: %s, %s of %s received)\n",
    $current->status->value,
    Status::isPaymentPaid($current->status) ? 'yes' : 'not yet',
    $current->amount_paid,
    $current->payer_amount
);
