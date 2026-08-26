<?php

declare(strict_types=1);

/**
 * Accept a payment: create an invoice, show the payer where to send the money, then poll it once.
 * In production you learn about the state change from a webhook — see webhook-receiver.php — and
 * poll only as a fallback.
 *
 * Run: OBLODAI_PUBLIC_ID=… OBLODAI_SECRET=… php examples/accept-payment.php
 */

require __DIR__ . '/_bootstrap.php';

use Oblodai\Contract\Enum\Network;
use Oblodai\Contract\Request\PaymentRequest;
use Oblodai\Exception\OblodaiException;
use Oblodai\Helper\Status;

// Credentials come from OBLODAI_PUBLIC_ID / OBLODAI_SECRET; the example stops with one line if
// they are missing. Against a local gateway over http, set OBLODAI_ALLOW_INSECURE=1 yourself.
$oblodai = example_client();

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
    // Codes worth branching on: payment.bad_amount, payment.below_minimum,
    // payment.minimum_unavailable (retryable), payment.unsupported_network, request.unknown_currency.
    example_fail('could not create the invoice', $err);
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
try {
    $current = $oblodai->payments->info(['order_id' => $invoice->order_id]);
} catch (OblodaiException $err) {
    example_fail('could not read the invoice back', $err);
}
printf(
    "\nnow: %s (paid: %s, %s of %s received)\n",
    $current->status->value,
    Status::isPaymentPaid($current->status) ? 'yes' : 'not yet',
    $current->amount_paid,
    $current->payer_amount
);
